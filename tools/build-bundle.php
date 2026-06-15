<?php

declare(strict_types=1);

$options = parse_args($argv);
$root = dirname(__DIR__);
$outDir = isset($options['out']) ? (string) $options['out'] : ($root . DIRECTORY_SEPARATOR . 'dist');
$version = release_value($root, 'version', '0.1.0');
$bundleName = 'pbb-landing-' . $version . '.zip';
$bundlePath = rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $bundleName;
$stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pbb-landing-bundle-' . date('YmdHis') . '-' . bin2hex(random_bytes_compat(4));

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "PHP zip extension is required to build the bundle.\n");
    exit(4);
}

try {
    $dirty = git_value($root, 'status --short', '');
    if ($dirty !== '' && empty($options['allow-dirty'])) {
        throw new RuntimeException('Working tree is dirty. Commit changes first or pass --allow-dirty for a non-canonical test build.');
    }

    ensure_dir($outDir);
    ensure_dir($stage);
    ensure_dir($stage . DIRECTORY_SEPARATOR . 'app');
    ensure_dir($stage . DIRECTORY_SEPARATOR . 'docs');

    $release = release_payload($root);
    $release['build'] = array(
        'version' => $version,
        'id' => 'pbb-landing-' . $version . '-' . date('Ymd.His'),
        'built_at' => date(DATE_ATOM),
        'git_commit' => git_value($root, 'rev-parse --short=12 HEAD', 'unknown'),
        'builder' => 'pbb-landing-bundle-builder',
    );
    if ($dirty !== '') {
        $release['build']['dirty'] = true;
    }

    write_json_file($stage . DIRECTORY_SEPARATOR . 'release.json', $release);
    write_json_file($stage . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'release.json', $release);

    copy_selected_app_payload($root, $stage . DIRECTORY_SEPARATOR . 'app');
    copy_tree($root . DIRECTORY_SEPARATOR . 'installer', $stage . DIRECTORY_SEPARATOR . 'installer', array());
    copy_file_if_exists($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'landing-installer-update-bundle-notes.md', $stage . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'landing-installer-update-bundle-notes.md');
    file_put_contents($stage . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'release-notes.md', "# PBB Landing {$version}\n\nInitial Landing installer-ready baseline.\n");

    $checksums = checksums($stage);
    file_put_contents($stage . DIRECTORY_SEPARATOR . 'checksums.sha256', implode("\n", $checksums) . "\n");

    if (is_file($bundlePath)) {
        unlink($bundlePath);
    }
    zip_dir($stage, $bundlePath);

    $summary = array(
        'bundle' => $bundlePath,
        'sha256' => hash_file('sha256', $bundlePath),
        'bytes' => filesize($bundlePath),
        'entries' => count($checksums) + 1,
        'build_id' => $release['build']['id'],
        'git_commit' => $release['build']['git_commit'],
        'dirty' => $dirty !== '',
    );
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    remove_tree($stage);
}

function parse_args(array $argv)
{
    $options = array();
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $name = substr($arg, 2);
        $value = true;
        $next = $argv[$i + 1] ?? null;
        if (is_string($next) && strpos($next, '--') !== 0) {
            $value = $next;
            $i++;
        }
        $options[$name] = $value;
    }
    return $options;
}

function release_payload($root)
{
    $path = $root . DIRECTORY_SEPARATOR . 'release.json';
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid release.json');
    }
    return $data;
}

function release_value($root, $key, $default)
{
    $release = release_payload($root);
    return isset($release[$key]) ? (string) $release[$key] : $default;
}

function copy_selected_app_payload($root, $target)
{
    foreach (array('config', 'docs', 'public', 'src', 'storage', 'README.md') as $name) {
        $source = $root . DIRECTORY_SEPARATOR . $name;
        $destination = $target . DIRECTORY_SEPARATOR . $name;
        if (is_dir($source)) {
            copy_tree($source, $destination, array(
                'logs/*.log',
                'installer/*',
                'landing.local.php',
            ));
        } elseif (is_file($source)) {
            copy_file_if_exists($source, $destination);
        }
    }
}

function copy_tree($source, $target, array $excludePatterns)
{
    ensure_dir($target);
    $source = rtrim((string) $source, DIRECTORY_SEPARATOR);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $sourcePath = $item->getPathname();
        $relative = str_replace('\\', '/', substr($sourcePath, strlen($source) + 1));
        if (excluded($relative, $excludePatterns)) {
            continue;
        }
        $targetPath = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            ensure_dir($targetPath);
        } else {
            copy_file_if_exists($sourcePath, $targetPath);
        }
    }
}

function excluded($relative, array $patterns)
{
    foreach ($patterns as $pattern) {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regex, $relative)) {
            return true;
        }
    }
    return false;
}

function copy_file_if_exists($source, $target)
{
    if (!is_file($source)) {
        return;
    }
    ensure_dir(dirname($target));
    copy($source, $target);
}

function checksums($stage)
{
    $rows = array();
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1));
        if ($relative === 'checksums.sha256') {
            continue;
        }
        $rows[] = hash_file('sha256', $item->getPathname()) . '  ' . $relative;
    }
    sort($rows, SORT_STRING);
    return $rows;
}

function zip_dir($source, $zipPath)
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP: ' . $zipPath);
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
        $zip->addFile($item->getPathname(), $relative);
    }
    $zip->close();
}

function write_json_file($path, array $data)
{
    ensure_dir(dirname($path));
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function ensure_dir($path)
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function git_value($root, $command, $default)
{
    $full = 'git -C ' . escapeshellarg($root) . ' ' . $command;
    exec($full, $output, $code);
    if ($code !== 0 || empty($output)) {
        return $default;
    }
    return trim((string) $output[0]);
}

function random_bytes_compat($length)
{
    if (function_exists('random_bytes')) {
        return random_bytes($length);
    }
    return openssl_random_pseudo_bytes($length);
}
