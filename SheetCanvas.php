<?php

namespace MADSCORE;

use SPTK\Core\{Color, Texture, TextureContext};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Canvas\Canvas;

/** Displays and edits the entire visible score sheet on one surface. */
final class SheetCanvas extends Canvas {

  public function __construct() {
    parent::__construct(background: new Color(255, 255, 255));
  }

  /** Paint all visible bars and the cursor in one coordinate system. */
  public function draw(Texture $surface, TextureContext $textures): void {
    $image = SheetPainter::render(Controller::score(), Controller::firstVisibleBar(), Controller::visibleColumns(), $surface->width(), $surface->height(), Controller::cursorStateForSheet(), Controller::melody(), Controller::selectionStateForSheet());
    $texture = $textures->textureFromImage($image);
    $texture->copyTo($surface, 0, 0);
    $texture->destroy();
  }

  public function handleInput(mixed $event): bool {
    return EditorInput::handle($this, $event);
  }

  /** Keep Return for note placement and leave editing only with Esc. */
  public function releaseNotification(int $key, int $modifiers): ?string {
    return $key === SDL::KEY_ESCAPE ? 'cancel' : null;
  }

  /** Keep the sheet bright while it is not the selected widget. */
  public function paintPixels(\SPTK\Rendering\PixelRenderer $renderer, \SPTK\Layout\Tile $area, bool $selected): void {
    parent::paintPixels($renderer, $area, true);
  }
}
