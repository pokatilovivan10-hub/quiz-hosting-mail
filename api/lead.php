<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply(int $status, array $data): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['ok'=>false,'error'=>'Используйте POST']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16384) reply(413,['ok'=>false,'error'=>'Слишком большой запрос']);
$raw=file_get_contents('php://input',false,null,0,16385);
if(strlen($raw)>16384) reply(413,['ok'=>false,'error'=>'Слишком большой запрос']);
$d=json_decode($raw,true);
if(!is_array($d)) reply(400,['ok'=>false,'error'=>'Некорректные данные']);
if(!empty($d['website'])) reply(400,['ok'=>false,'error'=>'Некорректные данные']);
$opts=[['Бук','Ясень','Сосна','Сосна, ступени шпонированные дубом'],['Прямая','Г-образная','П-образная','Ещё думаю'],['Дом уже готов, нужна лестница','Идёт ремонт / отделка','Дом строится','Пока только выбираю варианты']];
$qs=['Из какого дерева хотите лестницу?','Какую форму лестницы вы рассматриваете?','На каком этапе сейчас находится ваш дом?','Какая примерная высота между этажами?'];
if(($d['consent']??false)!==true||!is_string($d['phone']??null)||!preg_match('/^\+[1-9][0-9]{7,14}$/',$d['phone'])||!is_string($d['name']??null)||strlen($d['name'])>400||preg_match('/[\r\n]/',$d['name'])||!is_string($d['requestId']??null)||!preg_match('/^[a-zA-Z0-9-]{8,80}$/',$d['requestId'])||!is_array($d['answers']??null)||count($d['answers'])!==4) reply(400,['ok'=>false,'error'=>'Проверьте телефон и ответы на вопросы']);
$a=[];for($i=0;$i<4;$i++){ $v=$d['answers'][$i]['answer']??null;if(!is_string($v)||($i<3&&!in_array($v,$opts[$i],true))||($i===3&&(!preg_match('/^[0-9]{3}$/',$v)||(int)$v<200||(int)$v>400))) reply(400,['ok'=>false,'error'=>'Проверьте ответы на вопросы']);$a[]=['question'=>$qs[$i],'answer'=>$v]; }
$c=require __DIR__.'/config.php';
if(empty($c['sender'])) {
 $host=strtolower($_SERVER['SERVER_NAME']??'');
 if(preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/',$host)) $c['sender']='no-reply@'.preg_replace('/^www\./','',$host);
}
$dir=$c['storage_dir'];
$guard="<?php http_response_code(403); exit; ?>\n";
function readPrivate(string $file): string { $v=file_get_contents($file); return substr($v,strpos($v,"\n")+1); }

if(!is_dir($dir)&&!mkdir($dir,0700,true)) reply(500,['ok'=>false,'error'=>'Не удалось сохранить заявку. Позвоните +7(916)-216-97-81']);
// Файлы защищены .htaccess и PHP-заголовком; содержимое не выдаётся по HTTP.
$lock=fopen($dir.'/lock.php','c');if(!$lock||!flock($lock,LOCK_EX))reply(500,['ok'=>false,'error'=>'Попробуйте отправить заявку ещё раз']);
$id=$d['requestId'];$path=$dir.'/'.$id.'.php';if(is_file($path)){flock($lock,LOCK_UN);fclose($lock);reply(200,['ok'=>true,'id'=>$id]);}
$iphash=hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown');$ratefile=$dir.'/rate-'.$iphash.'.php';$recent=is_file($ratefile)?json_decode(readPrivate($ratefile),true):[];$recent=array_values(array_filter(is_array($recent)?$recent:[],fn($t)=>is_int($t)&&$t>time()-600));if(count($recent)>=8){flock($lock,LOCK_UN);fclose($lock);reply(429,['ok'=>false,'error'=>'Слишком много заявок. Попробуйте позже']);}
$utm=[];foreach(($d['utm']??[]) as $k=>$v){if(is_string($k)&&preg_match('/^(utm_[a-z_]+|yclid|gclid)$/',$k)&&is_string($v))$utm[$k]=substr($v,0,500);if(count($utm)>=12)break;}
$lead=['id'=>$id,'createdAt'=>gmdate('c'),'name'=>trim($d['name']),'phone'=>$d['phone'],'answers'=>$a,'consent'=>true,'utm'=>$utm,'notification'=>'pending'];
if(file_put_contents($path,$guard.json_encode($lead,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX)===false){flock($lock,LOCK_UN);fclose($lock);reply(500,['ok'=>false,'error'=>'Не удалось сохранить заявку. Попробуйте ещё раз']);}chmod($path,0600);$recent[]=time();file_put_contents($ratefile,$guard.json_encode($recent),LOCK_EX);flock($lock,LOCK_UN);fclose($lock);
$body="Новая заявка: лестница\n\nИмя: ".$lead['name']."\nТелефон: ".$lead['phone']."\n\n";foreach($a as $row)$body.=$row['question']."\n".$row['answer']."\n\n";$body.="Метки: ".json_encode($utm,JSON_UNESCAPED_UNICODE)."\nID: ".$id;
$valid=filter_var($c['recipient'],FILTER_VALIDATE_EMAIL)&&filter_var($c['sender'],FILTER_VALIDATE_EMAIL);
$sent=false;if($valid){try{$sent=mail($c['recipient'],'=?UTF-8?B?'.base64_encode('Новая заявка — деревянная лестница').'?=',$body,'From: '.$c['sender']."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8");}catch(Throwable $e){error_log('Quiz email failure');}}
$lead['notification']=$sent?'accepted_by_mail_server':($valid?'mail_failed':'not_configured');file_put_contents($path,$guard.json_encode($lead,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
reply(200,['ok'=>true,'id'=>$id]);
