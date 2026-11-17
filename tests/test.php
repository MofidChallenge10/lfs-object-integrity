<?php
declare(strict_types=1);
$script=dirname(__DIR__).'/bin/lfs-object-integrity.php';
$root=sys_get_temp_dir().'/lfs-object-integrity-'.bin2hex(random_bytes(5)); mkdir($root,0777,true);
function run(array $args): array { $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); return [proc_close($p),$out,$err]; }
try {
    mkdir($root.'/repo'); chdir($root.'/repo'); run(['git','init','-q']);
    $data='example binary payload'; $hash=hash('sha256',$data); $pointer="version https://git-lfs.github.com/spec/v1\noid sha256:$hash\nsize ".strlen($data)."\n";
    file_put_contents('asset.bin',$pointer); run(['git','add','asset.bin']);
    $objectDir=trim(run(['git','rev-parse','--git-path','lfs/objects'])[1]); mkdir($objectDir.'/'.substr($hash,0,2).'/'.substr($hash,2,2),0777,true);
    file_put_contents($objectDir.'/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash,$data);
    [$code,$out]=run(['php',$script,'--root','.','--json']); if ($code!==0 || !str_contains($out,'"verified": 1')) throw new RuntimeException('expected one verified object');
    unlink($objectDir.'/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash);
    [$code,$out]=run(['php',$script,'--root','.','--json']); if ($code!==1 || !str_contains($out,'"missing"')) throw new RuntimeException('expected missing object failure');
    echo "lfs-object-integrity tests passed\n";
} finally { chdir(sys_get_temp_dir()); exec('rm -rf '.escapeshellarg($root)); }
