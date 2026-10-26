<?php
$source_dir = 'C:/Users/bodma/Local Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline-2.2.12-changed-files/ofast-pipeline-2.2.12-changed-files/ofast-pipeline';
$target_dir = 'C:/Users/bodma/Local Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_dir));
$files = [];

foreach ($iterator as $item) {
    if ($item->isFile()) {
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source_dir))), '/');
        $files[] = $rel;
    }
}

foreach ($files as $f) {
    $tgt_file = $target_dir . '/' . $f;
    if (!file_exists($tgt_file)) {
        echo "[NEW FILE] " . $f . "\n";
    } else {
        $git_cmd = 'git -C "' . $target_dir . '" diff --name-only "' . $f . '"';
        $uncommitted = trim(shell_exec($git_cmd)) !== '';
        echo ($uncommitted ? "[LOCAL UNCOMMITTED EDITS] " : "[CLEAN IN GIT]           ") . $f . "\n";
    }
}


