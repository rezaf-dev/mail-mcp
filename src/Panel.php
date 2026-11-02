<?php
declare(strict_types=1);
namespace MailMcp;

final class Panel
{
    public function __construct(private Store $store, private array $config) {}

    public function handle(string $path, string $method): bool
    {
        $routes = ['/login','/logout','/authorize','/authorize/approve','/authorize/switch','/token','/register','/.well-known/oauth-authorization-server'];
        if (!str_starts_with($path, '/admin') && !in_array($path, $routes, true)) return false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('mailmcp_admin');
            session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax','path'=>'/']);
            session_start();
        }
        if ($path === '/register') { $this->registerClient(); return true; }
        if ($path === '/token') { $this->token(); return true; }
        if ($path === '/.well-known/oauth-authorization-server') { $this->metadata(); return true; }
        if ($path === '/authorize/approve') { $this->approve($method); return true; }
        if ($path === '/authorize/switch') { $this->switchAccount($method); return true; }
        if ($path === '/authorize') { $this->authorize($method); return true; }
        if ($path === '/login') { $this->login($method); return true; }
        if ($path === '/logout') { $this->postOnly($method); unset($_SESSION['account_id']); header('Location: /admin'); return true; }
        $this->admin($path, $method);
        return true;
    }

    private function admin(string $path, string $method): void
    {
        if (!$this->store->hasAdmin()) {
            if ($method === 'POST' && $path === '/admin/setup') { $this->setup(); return; }
            $this->page('Create administrator account', $this->form('/admin/setup', [['email','Email','email'],['password','Password (12+ characters)','password'],['confirm','Confirm password','password']]));
            return;
        }
        if (!$this->signedIn()) { header('Location: /login'); return; }
        $account = $this->currentAccount();
        if (!$account) { unset($_SESSION['account_id']); header('Location: /login'); return; }
        if ($path === '/admin/password' && $method === 'POST') { $this->changePassword($account); return; }
        if ($account['role'] !== 'admin') { $this->siteAccountPage($account); return; }

        if ($path === '/admin/domain/delete' && $method === 'POST') { $this->csrf(); $domain=strtolower(trim($_POST['domain']??''));$this->store->deleteDomain($domain);header('Location: /admin#domains');return; }
        if ($path === '/admin/domain' && $method === 'POST') { $this->saveDomain(); return; }
        if ($path === '/admin/smtp-test' && $method === 'POST') { $this->sendTest(); return; }
        if ($path === '/admin/account' && $method === 'POST') { $this->saveAccount(); return; }
        if ($path === '/admin/account/delete' && $method === 'POST') { $this->csrf();$this->store->deleteAccount((int)($_POST['id']??0));header('Location: /admin#accounts');return; }
        if ($path === '/admin/client/delete' && $method === 'POST') { $this->csrf();$this->store->deleteClient($_POST['client_id']??'');header('Location: /admin#oauth');return; }
        if ($path === '/admin/oauth' && $method === 'POST') { $this->csrf();$ttl=max(300,min(2592000,(int)($_POST['token_ttl']??3600)));$this->store->saveSetting('oauth_token_ttl',(string)$ttl);header('Location: /admin#oauth');return; }
        $this->dashboard($account);
    }

    private function setup(): void
    {
        $this->csrf();$email=filter_var(strtolower(trim($_POST['email']??'')),FILTER_VALIDATE_EMAIL);$pass=(string)($_POST['password']??'');
        if(!$email||strlen($pass)<12||$pass!==($_POST['confirm']??'')){$this->page('Create administrator account','<p>Use a valid email and matching password of at least 12 characters.</p>'.$this->form('/admin/setup',[['email','Email','email'],['password','Password (12+ characters)','password'],['confirm','Confirm password','password']]));return;}
        $this->store->createAdmin($email,$pass);session_regenerate_id(true);$_SESSION['account_id']=1;$_SESSION['csrf']=bin2hex(random_bytes(32));header('Location: /admin');
    }

    private function login(string $method): void
    {
        if($this->signedIn()){header('Location: /admin');return;}
        if($method==='POST'){$this->csrf();$account=$this->store->accountByEmail((string)($_POST['email']??''));if($account&&password_verify((string)($_POST['password']??''),$account['password_hash'])){session_regenerate_id(true);$_SESSION['account_id']=(int)$account['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));$to=(string)($_SESSION['after_login']??'/admin');unset($_SESSION['after_login']);header('Location: '.$to);return;}Logger::warning('account_login_failed');$error='<p class="error">Email or password was incorrect.</p>'; } else {$error='';}
        $this->page('Sign in',$error.$this->form('/login',[['email','Email','email'],['password','Password','password']]));
    }

    private function saveDomain(): void
    {
        $this->csrf();$domain=strtolower(trim($_POST['domain']??''));
        if(!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',$domain)){$this->page('Invalid domain','<p>Enter a valid domain name.</p><p><a href="/admin">Back</a></p>');return;}
        $old=$this->store->domain($domain)??[];$password=(string)($_POST['smtp_password']??'');if($password==='')$password=$this->decrypt((string)($old['password_enc']??''));
        $record=['from_name'=>trim($_POST['from_name']??''),'smtp_host'=>trim($_POST['smtp_host']??''),'smtp_port'=>max(1,min(65535,(int)($_POST['smtp_port']??587))),'smtp_encryption'=>in_array($_POST['smtp_encryption']??'tls',['tls','ssl','none'],true)?$_POST['smtp_encryption']:'tls','smtp_username'=>trim($_POST['smtp_username']??''),'password_enc'=>$this->encrypt($password),'hourly_limit'=>max(1,(int)($_POST['hourly_limit']??40)),'daily_limit'=>max(1,(int)($_POST['daily_limit']??200)),'max_recipients_per_message'=>max(1,(int)($_POST['max_recipients']??10))];
        if(!$record['smtp_host']||!$record['smtp_username']||!$password){$this->page('Missing SMTP settings','<p>SMTP host, username, and password are required.</p><p><a href="/admin">Back</a></p>');return;}
        $this->store->saveDomain($domain,$record);header('Location: /admin#domains');
    }

    private function sendTest(): void
    {
        $this->csrf();$domain=strtolower(trim($_POST['domain']??''));$to=filter_var(trim($_POST['to']??''),FILTER_VALIDATE_EMAIL);
        if(!$to||!isset($this->config['domains'][$domain])){$this->page('SMTP test failed','<p>Select a configured domain and enter a valid recipient address.</p><p><a href="/admin#smtp-test">Back</a></p>');return;}
        $args=['from'=>'smtp-test@'.$domain,'to'=>[$to],'subject'=>'Mail MCP SMTP test','text'=>'This test message confirms Mail MCP can send through the configured SMTP server for '.$domain.'.'];
        $upload=$_FILES['attachment']??null;
        if(is_array($upload)&&($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??''))||($upload['size']??PHP_INT_MAX)>$this->config['max_attachment']){$this->page('SMTP test failed','<p>The optional attachment could not be uploaded or exceeds the per-file size limit.</p><p><a href="/admin#smtp-test">Back</a></p>');return;}
            $bytes=file_get_contents((string)$upload['tmp_name']);if($bytes===false){$this->page('SMTP test failed','<p>Could not read the uploaded attachment.</p><p><a href="/admin#smtp-test">Back</a></p>');return;}
            $finfo=new \finfo(FILEINFO_MIME_TYPE);$args['attachments']=[['filename'=>(string)($upload['name']??'attachment'),'content_base64'=>base64_encode($bytes),'mime_type'=>$finfo->buffer($bytes)?:'application/octet-stream']];
        }
        try{$messageId=(new Mailer(dirname(__DIR__),$this->config))->send($args,[$domain]);$attachmentText=isset($args['attachments'])?' with attachment '.$this->esc((string)$upload['name']):'';$this->page('SMTP test sent','<p>SMTP accepted the test message'.$attachmentText.' for delivery to '.$this->esc($to).'. Message ID: <code>'.$this->esc($messageId).'</code></p><p>Check the recipient inbox and spam folder to confirm receipt.</p><p><a href="/admin#smtp-test">Back to admin</a></p>');}
        catch(\Throwable $e){Logger::error('smtp_test_failed',['exception'=>$e::class,'domain'=>$domain]);$this->page('SMTP test failed','<p>'.$this->esc($e->getMessage()).'</p><p>Check <code>storage/logs/mail-mcp-'.gmdate('Y-m-d').'.log</code> for details. Request ID: <code>'.$this->esc(Logger::requestId()).'</code></p><p><a href="/admin#smtp-test">Back to admin</a></p>');}
    }

    private function saveAccount(): void
    {
        $this->csrf();$email=filter_var(strtolower(trim($_POST['email']??'')),FILTER_VALIDATE_EMAIL);$password=(string)($_POST['password']??'');$domains=array_values(array_unique(array_filter((array)($_POST['domains']??[]),'is_string')));$allDomains=array_keys($this->store->domains());
        if(!$email||array_diff($domains,$allDomains)){$this->page('Account not saved','<p>Enter a valid email and select only configured domains.</p><p><a href="/admin#accounts">Back</a></p>');return;}
        $existing=$this->store->accountByEmail($email);
        if($existing){if($password!==''&&strlen($password)<12){$this->page('Account not saved','<p>New passwords must be at least 12 characters.</p><p><a href="/admin#accounts">Back</a></p>');return;}if($password!==''&&$existing['role']==='site')$this->store->saveAccountPassword((int)$existing['id'],$password);$this->store->setAccountDomains((int)$existing['id'],$domains);}
        else{if(strlen($password)<12){$this->page('Account not saved','<p>New account passwords must be at least 12 characters.</p><p><a href="/admin#accounts">Back</a></p>');return;}$this->store->createAccount($email,$password,$domains);}
        header('Location: /admin#accounts');
    }

    private function changePassword(array $account): void
    {
        $this->csrf();if(!password_verify((string)($_POST['current_password']??''),$account['password_hash'])||strlen((string)($_POST['new_password']??''))<12||($_POST['new_password']??'')!==($_POST['confirm']??'')){$this->page('Password not changed','<p>Check the current password and use matching new passwords of at least 12 characters.</p><p><a href="/admin">Back</a></p>');return;}
        $this->store->saveAccountPassword((int)$account['id'],(string)$_POST['new_password']);$this->page('Password updated','<p>Your password was updated.</p><p><a href="/admin">Continue</a></p>');
    }

    private function dashboard(array $account): void
    {
        $domains=$this->store->domains();$domainRows='';foreach($domains as $domain=>$d){$name=$this->esc($domain);$domainRows.='<tr><td>'.$name.'</td><td>'.(int)($d['smtp_port']??0).'</td><td>'.(int)($d['hourly_limit']??0).'/h · '.(int)($d['daily_limit']??0).'/day</td><td><form method="post" action="/admin/domain/delete">'.$this->csrfField().'<input type="hidden" name="domain" value="'.$name.'"><button>Remove</button></form></td></tr>';}
        $domainChoices='';foreach($domains as $domain=>$unused){$escaped=$this->esc($domain);$domainChoices.='<label class="check"><input type="checkbox" name="domains[]" value="'.$escaped.'"> '.$escaped.'</label>';}
        $accountRows='';foreach($this->store->accounts() as $user){$id=(int)$user['id'];$userDomains=array_map([$this,'esc'],$this->store->accountDomains($id));$accountRows.='<tr><td>'.$this->esc($user['email']).'</td><td>'.($user['role']==='admin'?'Administrator':'Site account').'</td><td>'.($userDomains?$this->esc(implode(', ',$userDomains)):'<em>No domains assigned</em>').'</td><td>'.($user['role']==='site'?'<form method="post" action="/admin/account/delete">'.$this->csrfField().'<input type="hidden" name="id" value="'.$id.'"><button>Remove</button></form>':'—').'</td></tr>';}
        $clients='';foreach($this->store->clients() as $c){$m=json_decode($c['metadata'],true)?:[];$id=$this->esc($c['client_id']);$clients.='<tr><td><code>'.$id.'</code></td><td>'.$this->esc(implode(', ',array_slice($m['redirect_uris']??[],0,2))).'</td><td><form method="post" action="/admin/client/delete">'.$this->csrfField().'<input type="hidden" name="client_id" value="'.$id.'"><button>Revoke</button></form></td></tr>';}
        $ttl=(int)$this->store->setting('oauth_token_ttl','3600');$domainForm=$this->form('/admin/domain',[['domain','Sending domain','text'],['from_name','From name','text'],['smtp_host','SMTP host','text'],['smtp_port','SMTP port','number'],['smtp_encryption','Encryption: tls / ssl / none','text'],['smtp_username','SMTP username','text'],['smtp_password','SMTP password (blank keeps existing)','password'],['hourly_limit','Hourly message limit','number'],['daily_limit','Daily message limit','number'],['max_recipients','Recipients per message limit','number']]);
        $testOptions='';foreach($domains as $domain=>$unused)$testOptions.='<option value="'.$this->esc($domain).'">'.$this->esc($domain).'</option>';
        $html='<p>Administrator: '.$this->esc($account['email']).' · MCP endpoint <code>'.$this->esc($this->config['base_url']).'</code></p>'.$this->logoutForm().'<h2 id="domains">SMTP domains</h2><table><tr><th>Domain</th><th>Port</th><th>Rate caps</th><th></th></tr>'.$domainRows.'</table><h3>Add or update domain</h3>'.$domainForm.'<h2 id="smtp-test">Test SMTP</h2><p>Sends a real test email and counts against that domain’s hourly and daily limits. Attach a small file to verify attachment delivery too.</p><form method="post" enctype="multipart/form-data" action="/admin/smtp-test">'.$this->csrfField().'<label>SMTP domain<select name="domain" required>'.$testOptions.'</select></label><label>Send test email to<input name="to" type="email" required></label><label>Optional attachment<input name="attachment" type="file"></label><button>Send test email</button></form><h2 id="accounts">Site accounts and sending access</h2><p>Each account, including the administrator, can send only from the domains assigned below. Enter an existing account email to change its domains; leave password blank to keep its password. The administrator password is changed separately below.</p><table><tr><th>Account</th><th>Role</th><th>Allowed sender domains</th><th></th></tr>'.$accountRows.'</table><h3>Add or update account</h3><form method="post" action="/admin/account">'.$this->csrfField().'<label>Email<input type="email" name="email" required></label><label>Password (12+ characters for new accounts; blank keeps existing)<input type="password" name="password"></label><fieldset><legend>Allowed sending domains</legend>'.$domainChoices.'</fieldset><button>Save account and access</button></form><h2 id="oauth">OAuth access</h2><p>Each ChatGPT OAuth approval is tied to the signing-in account and that account’s assigned domains.</p><form method="post" action="/admin/oauth">'.$this->csrfField().'<label>Access token lifetime in seconds<input name="token_ttl" type="number" min="300" max="2592000" value="'.$ttl.'"></label><button>Save OAuth setting</button></form><h3>Registered OAuth clients</h3><table><tr><th>Client ID</th><th>Redirect URI</th><th></th></tr>'.$clients.'</table><h2>Change password</h2>'.$this->passwordForm();
        $this->page('Mail MCP administration',$html);
    }

    private function siteAccountPage(array $account): void
    {
        $domains=$this->store->accountDomains((int)$account['id']);$html='<p>Signed in as '.$this->esc($account['email']).'. This account may send from: <strong>'.$this->esc($domains?implode(', ',$domains):'no domains assigned').'</strong>.</p>'.$this->logoutForm().'<h2>Change password</h2>'.$this->passwordForm();$this->page('Mail MCP site account',$html);
    }
    private function passwordForm(): string {return $this->form('/admin/password',[['current_password','Current password','password'],['new_password','New password (12+ characters)','password'],['confirm','Confirm new password','password']]);}
    private function logoutForm(): string {return '<form method="post" action="/logout">'.$this->csrfField().'<button>Sign out</button></form>';}

    private function registerClient(): void
    {
        $this->jsonHeaders();if($_SERVER['REQUEST_METHOD']!=='POST'){$this->jsonError(405,'invalid_client_metadata');return;}$data=json_decode((string)file_get_contents('php://input'),true);$uris=$data['redirect_uris']??[];
        if(!is_array($uris)||!$uris||count($uris)>20){$this->jsonError(400,'invalid_redirect_uri');return;}
        foreach($uris as $uri){if(!is_string($uri)){$this->jsonError(400,'invalid_redirect_uri');return;}$p=parse_url($uri);if(!is_array($p)||!in_array($p['scheme']??'', ['https','http'],true)||(($p['scheme']??'')==='http'&&!in_array($p['host']??'',['localhost','127.0.0.1','::1'],true))){$this->jsonError(400,'invalid_redirect_uri');return;}}
        $id='client_'.bin2hex(random_bytes(20));$data['client_id']=$id;$data['token_endpoint_auth_method']='none';$data['grant_types']=['authorization_code'];$data['response_types']=['code'];$this->store->registerClient($id,$data);http_response_code(201);echo json_encode($data,JSON_UNESCAPED_SLASHES);
    }
    private function metadata(): void {$u=$this->config['base_url'];$this->jsonHeaders();echo json_encode(['issuer'=>$u,'authorization_endpoint'=>$u.'/authorize','token_endpoint'=>$u.'/token','registration_endpoint'=>$u.'/register','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>['mail.send'],'client_id_metadata_document_supported'=>false],JSON_UNESCAPED_SLASHES);}
    private function authorize(string $method): void
    {
        if($method!=='GET'){$this->httpError(405,'Use GET.');return;}$q=$_GET;$client=$this->store->client((string)($q['client_id']??''));$redirect=(string)($q['redirect_uri']??'');$challenge=(string)($q['code_challenge']??'');
        if(!$client||!in_array($redirect,$client['redirect_uris']??[],true)||($q['response_type']??'')!=='code'||($q['code_challenge_method']??'')!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43,128}$/',$challenge)||!in_array('mail.send',preg_split('/\s+/',(string)($q['scope']??'')),true)){$this->httpError(400,'Invalid OAuth authorization request.');return;}
        $authorizationUrl='/authorize?'.http_build_query($q);if(!$this->signedIn()){$_SESSION['after_login']=$authorizationUrl;header('Location: /login');return;}$account=$this->currentAccount();if(!$account){$this->httpError(401,'Sign in required.');return;}$this->csrfToken();$_SESSION['pending_auth']=['client_id'=>$client['client_id'],'redirect_uri'=>$redirect,'challenge'=>$challenge,'state'=>(string)($q['state']??''),'account_id'=>(int)$account['id'],'authorization_url'=>$authorizationUrl];$domains=$this->store->accountDomains((int)$account['id']);$this->page('Authorize Mail MCP','<p>Signed in as '.$this->esc($account['email']).'. Allow this ChatGPT connection to send email from: <strong>'.$this->esc($domains?implode(', ',$domains):'no domains assigned').'</strong>.</p><form method="post" action="/authorize/approve">'.$this->csrfField().'<button>Allow</button></form><form method="post" action="/authorize/switch">'.$this->csrfField().'<button>Sign in with a different account</button></form><p><a href="/admin">Cancel</a></p>');
    }
    private function approve(string $method): void {if($method!=='POST'){$this->httpError(405,'Use POST.');return;}if(!$this->signedIn()){header('Location: /login');return;}$this->csrf();$pending=$_SESSION['pending_auth']??null;unset($_SESSION['pending_auth']);if(!is_array($pending)){$this->httpError(400,'Authorization request expired.');return;}$code=bin2hex(random_bytes(32));$this->store->addCode($code,$pending['client_id'],$pending['redirect_uri'],$pending['challenge'],$pending['state'],$pending['account_id']);$params=['code'=>$code];if($pending['state']!=='')$params['state']=$pending['state'];header('Location: '.$pending['redirect_uri'].'?'.http_build_query($params));}
    private function switchAccount(string $method): void {if($method!=='POST'){$this->httpError(405,'Use POST.');return;}$this->csrf();$pending=$_SESSION['pending_auth']??null;if(!is_array($pending)||empty($pending['authorization_url'])){$this->httpError(400,'Authorization request expired.');return;}$_SESSION['after_login']=$pending['authorization_url'];unset($_SESSION['account_id'],$_SESSION['pending_auth']);header('Location: /login');}
    private function token(): void {$this->jsonHeaders();if($_SERVER['REQUEST_METHOD']!=='POST'){$this->jsonError(405,'invalid_request');return;}parse_str((string)file_get_contents('php://input'),$p);if(($p['grant_type']??'')!=='authorization_code'){$this->jsonError(400,'unsupported_grant_type');return;}$clientId=(string)($p['client_id']??'');if(!$this->store->client($clientId)){$this->jsonError(400,'invalid_client');return;}$code=$this->store->redeemCode((string)($p['code']??''),$clientId,(string)($p['redirect_uri']??''));$verifier=(string)($p['code_verifier']??'');if(!$code||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/',$verifier)||!hash_equals($code['challenge'],rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='))){$this->jsonError(400,'invalid_grant');return;}$token=bin2hex(random_bytes(32));$ttl=(int)$this->store->setting('oauth_token_ttl','3600');$this->store->addToken($token,$clientId,$ttl,(int)$code['account_id']);echo json_encode(['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>$ttl,'scope'=>'mail.send']);}

    private function currentAccount(): ?array {return isset($_SESSION['account_id'])?$this->store->account((int)$_SESSION['account_id']):null;}
    private function signedIn(): bool {return $this->currentAccount()!==null;}
    private function csrf(): void {if(!hash_equals($_SESSION['csrf']??'',(string)($_POST['csrf']??''))){$this->httpError(400,'Invalid form token.');exit;}}
    private function csrfToken(): string {if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
    private function csrfField(): string {return '<input type="hidden" name="csrf" value="'.$this->esc($this->csrfToken()).'">';}
    private function postOnly(string $method): void {if($method!=='POST'){$this->httpError(405,'Use POST.');exit;}$this->csrf();}
    private function form(string $action,array $fields): string {$html='<form method="post" action="'.$action.'">'.$this->csrfField();foreach($fields as [$name,$label,$type]){$required=$name==='smtp_password'?'':' required';$defaults=['smtp_port'=>'587','hourly_limit'=>'40','daily_limit'=>'200','max_recipients'=>'10'];$html.='<label>'.$this->esc($label).'<input'.$required.' name="'.$this->esc($name).'" type="'.$type.'" value="'.($type==='number'?($defaults[$name]??''):'').'"></label>';}$html.='<button>Save</button></form>';return $html;}
    private function page(string $title,string $content): void {header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->esc($title).'</title><style>body{font:16px system-ui;max-width:940px;margin:3rem auto;padding:0 1rem;color:#18212b}h1,h2{margin-top:2rem}label{display:block;margin:12px 0}input,select{display:block;padding:10px;width:min(100%,520px);box-sizing:border-box;margin-top:4px}input[type=checkbox]{display:inline;width:auto}button{padding:9px 15px;cursor:pointer}table{border-collapse:collapse;width:100%}td,th{text-align:left;padding:8px;border-bottom:1px solid #ddd}code{overflow-wrap:anywhere}.error{color:#a22}fieldset{max-width:520px}.check{display:inline-block;margin-right:16px}</style></head><body><h1>'.$this->esc($title).'</h1>'.$content.'</body></html>';}
    private function encrypt(string $value): string {if($value==='')return ''; $key=base64_decode((string)$this->config['app_key'],true);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new \RuntimeException('APP_KEY must be base64-encoded 32 bytes.');$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,$key));}
    private function decrypt(string $value): string {if($value==='')return ''; $key=base64_decode((string)$this->config['app_key'],true);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new \RuntimeException('APP_KEY must be base64-encoded 32 bytes.');$raw=base64_decode($value,true);if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)return ''; $nonce=substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$nonce,$key);return $plain===false?'':$plain;}
    private function jsonHeaders(): void {header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');}
    private function jsonError(int $status,string $error): void {http_response_code($status);echo json_encode(['error'=>$error]);}
    private function httpError(int $status,string $message): void {http_response_code($status);header('Content-Type: text/plain; charset=utf-8');echo $message;}
    private function esc(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
