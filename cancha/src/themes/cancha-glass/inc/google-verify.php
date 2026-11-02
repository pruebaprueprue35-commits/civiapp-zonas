<?php
/** Offline, signed Google ID-token verification with cached Google X.509 public certificates. */
defined('ABSPATH') || exit;
function cancha_google_b64url_decode($value){
 if(!is_string($value) || $value==='' || strlen($value)>16000 || !preg_match('/^[A-Za-z0-9_-]+$/',$value))return false;
 return base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);
}
function cancha_google_public_certs(){
 $certs=get_transient('cancha_google_public_x509');if(is_array($certs) && $certs)return $certs;
 // Google's published X.509 certificate endpoint; never accept an administrator-supplied host.
 $response=wp_safe_remote_get('https://www.googleapis.com/oauth2/v1/certs',['timeout'=>12,'redirection'=>0,'limit_response_size'=>131072]);
 if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return [];
 $data=json_decode(wp_remote_retrieve_body($response),true);if(!is_array($data) || count($data)>12)return [];
 $safe=[];
 foreach($data as $kid=>$certificate){
  if(!is_string($kid)||!preg_match('/^[a-zA-Z0-9_-]{4,120}$/',$kid)||!is_string($certificate)||strlen($certificate)>10000||!str_contains($certificate,'BEGIN CERTIFICATE'))continue;
  if(openssl_x509_read($certificate))$safe[$kid]=$certificate;
 }
 if(!$safe)return [];
 $cc=(string)wp_remote_retrieve_header($response,'cache-control');
 $ttl=preg_match('/(?:^|,)\s*max-age=(\d+)/i',$cc,$m)?(int)$m[1]:1800;
 set_transient('cancha_google_public_x509',$safe,max(60,min(21600,$ttl)));
 return $safe;
}
function cancha_google_verify_jwt($jwt,$nonce,$audience,$public_certs=null){
 if(!is_string($jwt) || strlen($jwt)>15000)return new WP_Error('google_token','Token inválido.');
 $segments=explode('.',$jwt);if(count($segments)!==3)return new WP_Error('google_token','Token incompleto.');
 [$header64,$payload64,$sig64]=$segments;
 $header=json_decode(cancha_google_b64url_decode($header64)?:'',true);
 $claims=json_decode(cancha_google_b64url_decode($payload64)?:'',true);
 $signature=cancha_google_b64url_decode($sig64);
 if(!is_array($header)||!is_array($claims)||!is_string($signature)||($header['alg']??'')!=='RS256'||!is_string($header['kid']??null))return new WP_Error('google_token','Firma no reconocida.');
 $certs=$public_certs===null?cancha_google_public_certs():$public_certs;
 $certificate=$certs[$header['kid']]??'';
 if(!$certificate||!function_exists('openssl_pkey_get_public'))return new WP_Error('google_cert','Certificado público no disponible.');
 $key=openssl_pkey_get_public($certificate);
 if(!$key||openssl_verify($header64.'.'.$payload64,$signature,$key,OPENSSL_ALGO_SHA256)!==1)return new WP_Error('google_signature','La firma de Google no es válida.');
 $now=time();
 if(!hash_equals((string)$audience,(string)($claims['aud']??'')) ||
    !in_array(($claims['iss']??''),['https://accounts.google.com','accounts.google.com'],true) ||
    (int)($claims['exp']??0)<=$now || (int)($claims['iat']??0)>$now+60 || (int)($claims['iat']??0)<$now-DAY_IN_SECONDS ||
    !is_string($claims['nonce']??null) || !hash_equals((string)$nonce,$claims['nonce']) ||
    !in_array($claims['email_verified']??false,[true,'true'],true) ||
    !is_email($claims['email']??'') || empty($claims['sub']) || !preg_match('/^[0-9]{10,40}$/',(string)$claims['sub'])){
    return new WP_Error('google_claims','Identidad de Google incompleta o caducada.');
 }
 return $claims;
}
