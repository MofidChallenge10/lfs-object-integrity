#!/usr/bin/env php
<?php
declare(strict_types=1);

function runCommand(array $args, string $cwd): string {
    $proc = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd);
    if (!is_resource($proc)) throw new RuntimeException('could not start git');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code=proc_close($proc); if ($code!==0) throw new RuntimeException(trim($err) ?: 'git command failed'); return $out;
}
function audit(string $root): array {
    $root=realpath($root) ?: throw new RuntimeException('root directory does not exist');
    $files=runCommand(['git','ls-files','-z'],$root);
    $objectDir=trim(runCommand(['git','rev-parse','--git-path','lfs/objects'],$root));
    if ($objectDir==='') throw new RuntimeException('could not locate Git LFS object directory');
    if ($objectDir[0]!=='/') $objectDir=$root.DIRECTORY_SEPARATOR.$objectDir;
    $report=['tracked_pointers'=>0,'verified'=>0,'missing'=>[],'size_mismatches'=>[]];
    foreach (explode("\0",$files) as $relative) {
        if ($relative==='') continue;
        $path=$root.DIRECTORY_SEPARATOR.$relative;
        if (!is_file($path) || !is_readable($path)) continue;
        $handle=fopen($path,'rb'); if ($handle===false) continue;
        $header=fread($handle,1024); fclose($handle);
        if (!str_starts_with($header,"version https://git-lfs.github.com/spec/v1\n")) continue;
        if (!preg_match('/^oid sha256:([a-f0-9]{64})$/m',$header,$oid) || !preg_match('/^size ([0-9]+)$/m',$header,$size)) {
            throw new RuntimeException("malformed LFS pointer: {$relative}");
        }
        $report['tracked_pointers']++; $hash=$oid[1]; $expected=(int)$size[1];
        $object=$objectDir.DIRECTORY_SEPARATOR.substr($hash,0,2).DIRECTORY_SEPARATOR.substr($hash,2,2).DIRECTORY_SEPARATOR.$hash;
        if (!is_file($object)) { $report['missing'][]=['path'=>$relative,'oid'=>$hash,'expected_size'=>$expected]; continue; }
        $actual=filesize($object);
        if ($actual!==$expected) $report['size_mismatches'][]=['path'=>$relative,'expected_size'=>$expected,'actual_size'=>$actual];
        else $report['verified']++;
    }
    return $report;
}
$root='.'; $json=false;
for ($i=1;$i<$argc;$i++) {
    if ($argv[$i]==='--root' && isset($argv[$i+1])) $root=$argv[++$i];
    elseif ($argv[$i]==='--json') $json=true;
    elseif ($argv[$i]==='--help' || $argv[$i]==='-h') { echo "Usage: lfs-object-integrity.php [--root PATH] [--json]\n"; exit(0); }
    else { fwrite(STDERR,"Unknown argument: {$argv[$i]}\n"); exit(2); }
}
try { $result=audit($root); }
catch (Throwable $e) { fwrite(STDERR,"error: {$e->getMessage()}\n"); exit(2); }
if ($json) echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
else {
    echo "Tracked LFS pointers: {$result['tracked_pointers']}\nVerified objects: {$result['verified']}\nMissing objects: ".count($result['missing'])."\nMismatched sizes: ".count($result['size_mismatches'])."\n";
    foreach ($result['missing'] as $item) echo "MISSING  {$item['path']}\n";
    foreach ($result['size_mismatches'] as $item) echo "SIZE MISMATCH  {$item['path']}\n";
}
exit((count($result['missing'])+count($result['size_mismatches']))>0 ? 1 : 0);
