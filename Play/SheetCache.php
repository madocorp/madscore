<?php

namespace MADSCORE\Play;

use MADSCORE\Render\SheetPainter;
use MADSCORE\Score\Score;
use SPTK\Core\{AppData, RasterImage};

/** Keep a few rendered score pages in memory while a worker prepares the rest. */
final class SheetCache {

  private array $images = [];
  private ?string $directory = null;
  private $worker = null;

  public function __construct(private Score $score, private int $columns, private int $width,
    private int $height, private array $cursor, private int $melody, int $firstPage) {
    $count = $this->pageCount();
    $this->render($firstPage);
    if ($firstPage + 1 < $count) {
      $this->render($firstPage + 1);
    }
    if ($count <= count($this->images)) {
      return;
    }
    $this->directory = AppData::path() . '/play-' . bin2hex(random_bytes(8));
    if (!mkdir($this->directory, 0700)) {
      throw new \RuntimeException('Could not create the playback page cache.');
    }
    $input = ['score' => $score->data(), 'columns' => $columns, 'width' => $width,
      'height' => $height, 'cursor' => $cursor, 'melody' => $melody, 'skip' => array_keys($this->images)];
    try {
      foreach ($this->images as $page => $image) {
        if (file_put_contents($this->directory . '/page-' . $page . '.raw', $image->pixels) !== strlen($image->pixels)) {
          throw new \RuntimeException('Could not store a prepared playback page.');
        }
      }
      $json = json_encode($input, JSON_THROW_ON_ERROR);
      if (file_put_contents($this->directory . '/input.json', $json) !== strlen($json)) {
        throw new \RuntimeException('Could not store the playback page request.');
      }
      $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
      $this->worker = proc_open([PHP_BINARY, dirname(__DIR__) . '/Play/render-pages.php', $this->directory],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $this->directory . '/error.log', 'w']], $pipes);
      if (!is_resource($this->worker)) {
        throw new \RuntimeException('Could not start the playback page renderer.');
      }
    } catch (\Throwable $error) {
      $this->close();
      throw $error;
    }
  }

  public function pageCount(): int {
    return max(1, (int)ceil(count($this->score->bars) / $this->columns));
  }

  /** Return a ready page without blocking the audio event loop. */
  public function page(int $index): ?RasterImage {
    if (isset($this->images[$index])) {
      return $this->images[$index];
    }
    if ($this->directory === null) {
      return null;
    }
    $path = $this->directory . '/page-' . $index . '.raw';
    if (!is_file($path)) {
      return null;
    }
    $pixels = file_get_contents($path);
    if ($pixels === false || strlen($pixels) !== $this->width * $this->height * 4) {
      return null;
    }
    $this->images[$index] = new RasterImage($this->width, $this->height, $pixels);
    if (count($this->images) > 3) {
      foreach (array_keys($this->images) as $old) {
        if ($old !== $index) {
          unset($this->images[$old]);
          break;
        }
      }
    }
    return $this->images[$index];
  }

  /** Report a worker failure when a requested page did not arrive. */
  public function failure(): ?string {
    if (!is_resource($this->worker)) {
      return null;
    }
    $status = proc_get_status($this->worker);
    if ($status['running']) {
      return null;
    }
    $error = trim((string)@file_get_contents($this->directory . '/error.log'));
    return $status['exitcode'] === 0 ? null : ($error ?: 'The page renderer stopped.');
  }

  public function close(): void {
    if (is_resource($this->worker)) {
      if (proc_get_status($this->worker)['running']) {
        proc_terminate($this->worker);
      }
      proc_close($this->worker);
      $this->worker = null;
    }
    if ($this->directory !== null) {
      foreach (glob($this->directory . '/*') ?: [] as $path) {
        unlink($path);
      }
      rmdir($this->directory);
      $this->directory = null;
    }
    $this->images = [];
  }

  private function render(int $index): void {
    $this->images[$index] = SheetPainter::render($this->score, $index * $this->columns,
      $this->columns, $this->width, $this->height, $this->cursor, $this->melody, null, true, 0, false);
  }
}
