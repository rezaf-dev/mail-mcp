<?php
declare(strict_types=1);
namespace MailMcp;

final class Store
{
    private \PDO $db;
    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create storage directory.'); }
        $this->db = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;
            CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS oauth_clients (client_id TEXT PRIMARY KEY, metadata TEXT NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS oauth_codes (code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, redirect_uri TEXT NOT NULL, challenge TEXT NOT NULL, state TEXT, expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS oauth_tokens (token_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL);');
    }
    public function pdo(): \PDO { return $this->db; }
    public function hasAdmin(): bool { return (int) $this->db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0; }
    public function adminByEmail(string $email): ?array { $s=$this->db->prepare('SELECT * FROM admins WHERE email=?'); $s->execute([strtolower($email)]); return $s->fetch() ?: null; }
    public function createAdmin(string $email, string $password): void { $s=$this->db->prepare('INSERT INTO admins(email,password_hash,created_at) VALUES(?,?,?)'); $s->execute([strtolower($email),password_hash($password,PASSWORD_DEFAULT),time()]); }
    public function setting(string $key, ?string $default=null): ?string { $s=$this->db->prepare('SELECT value FROM settings WHERE key=?'); $s->execute([$key]); $v=$s->fetchColumn(); return $v===false ? $default : (string)$v; }
    public function saveSetting(string $key, string $value): void { $s=$this->db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value'); $s->execute([$key,$value]); }
    public function domains(): array { $rows=$this->db->query('SELECT key,value FROM settings WHERE key LIKE "domain:%"')->fetchAll(); $out=[]; foreach($rows as $row){$out[substr($row['key'],7)]=json_decode($row['value'],true)?:[];} return $out; }
    public function domain(string $domain): ?array { $v=$this->setting('domain:' . strtolower($domain)); return $v===null ? null : json_decode($v,true); }
    public function saveDomain(string $domain, array $config): void { $this->saveSetting('domain:' . strtolower($domain), json_encode($config,JSON_THROW_ON_ERROR)); }
    public function deleteDomain(string $domain): void { $s=$this->db->prepare('DELETE FROM settings WHERE key=?'); $s->execute(['domain:' . strtolower($domain)]); }
    public function registerClient(string $id, array $metadata): void { $s=$this->db->prepare('INSERT OR REPLACE INTO oauth_clients(client_id,metadata,created_at) VALUES(?,?,?)'); $s->execute([$id,json_encode($metadata,JSON_THROW_ON_ERROR),time()]); }
    public function client(string $id): ?array { $s=$this->db->prepare('SELECT metadata FROM oauth_clients WHERE client_id=?'); $s->execute([$id]); $v=$s->fetchColumn(); return $v===false ? null : json_decode($v,true); }
    public function clients(): array { return $this->db->query('SELECT client_id,metadata,created_at FROM oauth_clients ORDER BY created_at DESC')->fetchAll(); }
    public function deleteClient(string $id): void { $s=$this->db->prepare('DELETE FROM oauth_clients WHERE client_id=?'); $s->execute([$id]); $s=$this->db->prepare('DELETE FROM oauth_tokens WHERE client_id=?'); $s->execute([$id]); }
    public function addCode(string $code, string $client, string $redirect, string $challenge, ?string $state): void { $s=$this->db->prepare('INSERT INTO oauth_codes(code_hash,client_id,redirect_uri,challenge,state,expires_at) VALUES(?,?,?,?,?,?)'); $s->execute([hash('sha256',$code),$client,$redirect,$challenge,$state,time()+300]); }
    public function redeemCode(string $code, string $client, string $redirect): ?array
    {
        $this->db->beginTransaction();
        try { $s=$this->db->prepare('SELECT * FROM oauth_codes WHERE code_hash=?'); $s->execute([hash('sha256',$code)]); $row=$s->fetch();
            if (!$row || $row['used'] || $row['expires_at']<time() || !hash_equals($row['client_id'],$client) || !hash_equals($row['redirect_uri'],$redirect)) { $this->db->rollBack(); return null; }
            $u=$this->db->prepare('UPDATE oauth_codes SET used=1 WHERE code_hash=? AND used=0'); $u->execute([$row['code_hash']]);
            if ($u->rowCount()!==1) { $this->db->rollBack(); return null; } $this->db->commit(); return $row;
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }
    public function addToken(string $token, string $client, int $ttl): void { $s=$this->db->prepare('INSERT INTO oauth_tokens(token_hash,client_id,expires_at,created_at) VALUES(?,?,?,?)'); $s->execute([hash('sha256',$token),$client,time()+$ttl,time()]); }
    public function validToken(string $token): bool { $s=$this->db->prepare('SELECT expires_at FROM oauth_tokens WHERE token_hash=?'); $s->execute([hash('sha256',$token)]); $exp=$s->fetchColumn(); if($exp===false)return false; if((int)$exp<time()){ $d=$this->db->prepare('DELETE FROM oauth_tokens WHERE token_hash=?');$d->execute([hash('sha256',$token)]);return false;}return true; }
}
