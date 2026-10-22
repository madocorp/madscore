<?php

namespace MADSCORE\App;

use MADSCORE\Render\SheetPainter;

use SPTK\Core\{Color, Texture, TextureContext};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Canvas\Canvas;

/** Displays and edits the entire visible score sheet on one surface. */
final class SheetCanvas extends Canvas {

  private ?Texture $playBase = null;
  private ?array $lastBeat = null;
  private ?int $lastPage = null;
  private int $surfaceWidth = 0;
  private int $surfaceHeight = 0;

  public function __construct() {
    parent::__construct(background: new Color(255, 255, 255));
  }

  /** Paint all visible bars and the cursor in one coordinate system. */
  public function draw(Texture $surface, TextureContext $textures): void {
    $this->surfaceWidth = $surface->width();
    $this->surfaceHeight = $surface->height();
    $frame = Controller::playbackFrame();
    if ($frame !== null && $surface->width() === $frame['width'] && $surface->height() === $frame['height']) {
      $page = $frame['cache']->page($frame['page']);
      if ($page !== null) {
        if ($this->playBase === null || $this->playBase->destroyed()
          || $this->lastPage !== $frame['page']) {
          $this->playBase?->destroy();
          $this->playBase = $textures->textureFromImage($page);
          $this->playBase->copyTo($surface, 0, 0);
          $this->lastPage = $frame['page'];
          $this->lastBeat = null;
        }
        if ($this->lastBeat !== $frame['rect']) {
          if ($this->lastBeat !== null) {
            $this->copyBeat($surface, $this->lastBeat);
          }
          [$x, $y, $width, $height] = $frame['rect'];
          $surface->fillRect($x, $y, $width, $height, '#dca00038');
          $this->lastBeat = $frame['rect'];
        }
        return;
      }
    }
    $this->resetPlayback();
    $image = SheetPainter::render(Controller::score(), Controller::firstVisibleBar(), Controller::visibleColumns(), $surface->width(), $surface->height(), Controller::cursorStateForSheet(), Controller::melody(), Controller::selectionStateForSheet(), true,
      composer: Controller::composerPreview());
    $texture = $textures->textureFromImage($image);
    $texture->copyTo($surface, 0, 0);
    $texture->destroy();
  }

  public function preservePixels(): bool {
    return Controller::playbackFrame() !== null;
  }

  /** Return the measured pixel size of this canvas's most recent draw. */
  public function surfaceSize(): array {
    return [$this->surfaceWidth, $this->surfaceHeight];
  }

  /** Discard a previous performance's page texture before the next one starts. */
  public function resetPlayback(): void {
    $this->playBase?->destroy();
    $this->playBase = null;
    $this->lastPage = null;
    $this->lastBeat = null;
  }

  /** Restore one old highlight rectangle from the pristine page texture. */
  private function copyBeat(Texture $surface, array $rect): void {
    [$x, $y, $width, $height] = $rect;
    $this->playBase?->copy($surface, $x, $y, $x, $y, $width, $height);
  }

  public function handleInput(mixed $event): bool {
    return EditorInput::handle($this, $event);
  }

  /** Keep Return in the editor for note placement or pitch changes. */
  public function releaseNotification(int $key, int $modifiers): ?string {
    return $key === SDL::KEY_RETURN ? null : parent::releaseNotification($key, $modifiers);
  }
}
