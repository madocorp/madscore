<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Render\SheetPainter;
use MADSCORE\Score\Score;
use MADSCORE\Score\Project;

try {
  $directory = $argv[1] ?? throw new RuntimeException('Missing page cache directory.');
  $input = json_decode(file_get_contents($directory . '/input.json'), true, 512, JSON_THROW_ON_ERROR);
  $score = Score::fromData($input['score']);
  $project = isset($input['project']) ? Project::fromData($input['project']) : null;
  $columns = $input['columns'];
  $pages = (int)ceil(count($score->bars) / $columns);
  for ($page = 0; $page < $pages; $page++) {
    if (in_array($page, $input['skip'], true)) {
      continue;
    }
    $image = SheetPainter::render($score, $page * $columns, $columns,
      $input['width'], $input['height'], $input['cursor'], $input['melody'], null, true, 0, false,
      project: $project);
    $path = $directory . '/page-' . $page . '.raw';
    if (file_put_contents($path . '.tmp', $image->pixels) !== strlen($image->pixels)
      || !rename($path . '.tmp', $path)) {
      throw new RuntimeException('Could not write playback page ' . $page . '.');
    }
  }
} catch (Throwable $error) {
  fwrite(STDERR, $error->getMessage() . "\n");
  exit(1);
}
