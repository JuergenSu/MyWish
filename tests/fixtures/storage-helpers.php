<?php
// Isolated fixtures only: never used by the application.
function removeTestTree(string $path): void
{
    if (!is_dir($path)) { if (is_file($path)) { unlink($path); }return; }
    foreach (scandir($path) as $file) { if ($file==='.' || $file==='..') { continue; }removeTestTree($path.'/'.$file); }rmdir($path);
}
