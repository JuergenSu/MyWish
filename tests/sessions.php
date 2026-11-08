<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Store.php';
require dirname(__DIR__).'/src/SessionStore.php';
use MyWish\{Store,SessionStore};
$checks=0;
function check(bool $value,string $label): void { global $checks; if (!$value) { throw new RuntimeException($label); } $checks++; }
$directory=sys_get_temp_dir().'/mywish-sessions-'.bin2hex(random_bytes(6));
$handler=new SessionStore($directory);
try {
    $id=$handler->create_sid(); $path=$directory.'/session-'.$id.'.php';
    check((bool)preg_match('/^[a-f0-9]{64}$/D',$id),'Unpredictable session ID');
    check(!$handler->validateId($id),'Unknown session IDs cannot be fixed by a client');
    check($handler->read('../../outside')===false,'Path traversal cannot create a file');
    check($handler->write($id,'private-session-data'),'Write guarded session'); $handler->close();
    check(file_get_contents($path)===Store::GUARD.'private-session-data','PHP guard protects session payload');
    check((fileperms($path)&0777)===0600,'Session file only readable by PHP user');
    check($handler->validateId($id),'Existing session accepted');
    touch($path,time()-13*86400);
    check($handler->validateId($id) && $handler->read($id)==='private-session-data','Session survives 13 days without activity');
    check($handler->write($id,'short'),'A request refreshes the session'); $handler->close();
    clearstatcache(true,$path);
    check(filemtime($path)>=time()-2 && file_get_contents($path)===Store::GUARD.'short','Refresh removes trailing old data and updates inactivity timestamp');
    touch($path,time()-SessionStore::LIFETIME);
    check(!$handler->validateId($id) && $handler->read($id)==='','Exactly 14 inactive days expire the session'); $handler->close();
    $fresh=$handler->create_sid(); $handler->write($fresh,'fresh'); $handler->close();
    check($handler->gc(1)===1 && !is_file($path),'Cleanup uses the application lifetime, not a shorter host value');
    check($handler->validateId($fresh),'Fresh session survives cleanup');
    $handler->read($fresh);
    $freshPath=$directory.'/session-'.$fresh.'.php'; touch($freshPath,time()-15*86400);
    check((new SessionStore($directory))->gc(1)===0 && is_file($freshPath),'Cleanup skips a session with an active request lock');
    $handler->write($fresh,'private-before-logout');
    $marker=$directory.'/child-started';
    $code='require '.var_export(dirname(__DIR__).'/src/Store.php',true).';require '.var_export(dirname(__DIR__).'/src/SessionStore.php',true).';file_put_contents('.var_export($marker,true).',"started");$s=new MyWish\\SessionStore('.var_export($directory,true).');echo json_encode($s->read('.var_export($fresh,true).'));$s->close();';
    $process=proc_open([PHP_BINARY,'-n','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Start concurrent session reader'); fclose($pipes[0]);
    for ($i=0;$i<200 && !is_file($marker);$i++) { usleep(10000); }
    check(is_file($marker),'Concurrent reader starts before logout');
    check($handler->destroy($fresh),'Logout removes data under the existing lock'); $handler->close();
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process)===0 && $output==='""' && $errors==='','Waiting request cannot revive credentials after logout');
    check(!$handler->validateId($fresh) && file_get_contents($freshPath)===Store::GUARD,'Logged-out session cannot be reused');
    echo "OK: $checks persistent-session/security checks\n";
} finally {
    $handler->close(); foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
}
