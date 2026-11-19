<?php
declare(strict_types=1);

// Reproduces supported upgrades in randomly named disposable databases only.
$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$db = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$schema=file_get_contents($root.'/database/schema.sql'); $seed=file_get_contents($root.'/database/seed.sql');
$m2=file_get_contents($root.'/database/migrations/002_full_project.sql');
$m3=file_get_contents($root.'/database/migrations/003_project_expansion.sql');
$m4=file_get_contents($root.'/database/migrations/004_auth_login_limits.sql');
$legacy=substr($schema,0,strpos($schema,'CREATE TABLE customer_addresses'));
$legacy=preg_replace('/^    (is_active BOOLEAN NOT NULL DEFAULT TRUE,|CONSTRAINT chk_products_active CHECK \(is_active IN \(0, 1\)\),)\R/m','',$legacy,1);
$legacy=str_replace('    CONSTRAINT chk_products_active CHECK (is_active IN (0, 1)),','',$legacy);
$legacySeed=substr($seed,0,strpos($seed,'-- Expanded project examples.'))."\nCOMMIT;\n";
$catalogSeed=substr($seed,0,strpos($seed,'-- Full project demo data'))."\nCOMMIT;\n";
$catalog=substr($legacy,strpos($legacy,'CREATE TABLE categories'),strpos($legacy,'-- Customer,')-strpos($legacy,'CREATE TABLE categories'));
$created=[];
function ensure(bool $condition,string $description): void { if(!$condition)throw new RuntimeException($description); echo "PASS: $description\n"; }
function definitions(PDO $db): array {
    $out=[]; foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $ddl=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $lines=array_map(static fn($line)=>rtrim(trim($line),','),explode("\n",preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl)));
        sort($lines); $out[$table]=$lines;
    } ksort($out); return $out;
}
function rows(PDO $db): array {
    $out=[]; foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $out[$table]=$db->query("SELECT * FROM `$table`")->fetchAll(); usort($out[$table], static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
    } return $out;
}
function unchanged(array $before,array $after,string $path): void {
    foreach($before as $table=>$records) {
        if($table==='products')foreach($after[$table] as &$record){if(!array_key_exists('is_active',$records[0]??[]))unset($record['is_active']);}
        unset($record);
        ensure($records===$after[$table], "$path preserves $table rows");
    }
}
try {
    foreach(['fresh','build1','nine','fourteen'] as $label){
        $name='foodwell_upgrade_'.$label.'_'.bin2hex(random_bytes(4)); $db->exec("CREATE DATABASE `$name`"); $created[$label]=$name;
    }
    $db->exec('USE `'.$created['fresh'].'`'); $db->exec($schema); $fresh=definitions($db);
    $db->exec('USE `'.$created['build1'].'`');$db->exec($catalog);$db->exec($catalogSeed);$before=rows($db);
    $db->exec($m2);$db->exec($m3);$db->exec($m4);unchanged($before,rows($db),'Build 1 to current');ensure(definitions($db)===$fresh,'Build 1 + 002 + 003 + 004 matches fresh schema');
    $db->exec('USE `'.$created['nine'].'`');$db->exec($legacy);$db->exec($legacySeed);$before=rows($db);
    $db->exec($m3);$db->exec($m4);unchanged($before,rows($db),'Nine-table to current');ensure(definitions($db)===$fresh,'Nine-table + 003 + 004 matches fresh schema');
    $db->exec('USE `'.$created['fourteen'].'`');$db->exec($legacy);$db->exec($legacySeed);$db->exec($m3);$before=rows($db);
    $db->exec($m4);unchanged($before,rows($db),'Fourteen-table to current');ensure(definitions($db)===$fresh,'Fourteen-table + 004 matches fresh schema');
    echo "All supported upgrade paths matched the fresh schema.\n";
} finally {foreach($created as $name){$db->exec("DROP DATABASE `$name`");}}
