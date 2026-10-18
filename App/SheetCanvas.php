<?php

namespace MADSCORE\App;

use MADSCORE\Render\PagePreview;
use MADSCORE\Render\SheetPainter;

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
    $image = Controller::isDesign()
      ? SheetPainter::render(Controller::score(), Controller::firstVisibleBar(), Controller::visibleColumns(), $surface->width(), $surface->height(), Controller::cursorStateForSheet(), Controller::melody(), Controller::selectionStateForSheet(), true)
      : PagePreview::render(Controller::score(), Controller::previewPage(), $surface->width(), $surface->height());
    $texture = $textures->textureFromImage($image);
    $texture->copyTo($surface, 0, 0);
    $texture->destroy();
  }

  public function handleInput(mixed $event): bool {
    return EditorInput::handle($this, $event);
  }

  /** Keep Return in the editor for note placement or pitch changes. */
  public function releaseNotification(int $key, int $modifiers): ?string {
    return $key === SDL::KEY_RETURN ? null : parent::releaseNotification($key, $modifiers);
  }
}
