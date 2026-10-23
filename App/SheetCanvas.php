<?php

namespace MADSCORE\App;

use MADSCORE\Render\SheetPainter;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Score;

use SPTK\Core\{Color, Texture, TextureContext};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Canvas\Canvas;

/** Displays and edits the entire visible score sheet on one surface. */
final class SheetCanvas extends Canvas {

  private ?Texture $playBase = null;
  private ?Texture $editBase = null;
  private ?Score $visibleScore = null;
  private ?array $editKey = null;
  private array $editBands = [];
  private bool $contentDirty = true;
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
    $frame = ControllerPlayback::playbackFrame();
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
    $score = Controller::score();
    $firstBar = Controller::firstVisibleBar();
    $columns = Controller::visibleColumns();
    $melody = ControllerNoteEditing::melody();
    $cursor = ControllerSelection::cursorStateForSheet();
    $selection = ControllerSelection::selectionStateForSheet();
    $key = [spl_object_id($score), $firstBar, $columns, $surface->width(), $surface->height(), $melody];
    $cursorBandVisible = in_array($cursor['octave'], $this->editBands, true)
      || ($cursor['pitch'] === 12 && in_array($cursor['octave'] + 1, $this->editBands, true));
    if ($this->contentDirty || $this->editKey !== $key || $this->editBase === null
      || $this->editBase->destroyed() || !$cursorBandVisible) {
      $this->editBase?->destroy();
      $this->visibleScore = self::visibleWindow($score, $firstBar, $columns);
      $this->editBands = MelodyRange::bands($score->visibleCopy(), $melody,
        $cursor['octave'], $cursor['pitch']);
      $image = SheetPainter::render($this->visibleScore, $firstBar, $columns,
        $surface->width(), $surface->height(), melody: $melody, design: true,
        composer: Controller::composerPreview(), fixedBands: $this->editBands);
      $this->editBase = $textures->textureFromImage($image);
      $this->editKey = $key;
      $this->contentDirty = false;
    }
    if ($selection !== null) {
      $image = SheetPainter::render($this->visibleScore, $firstBar, $columns,
        $surface->width(), $surface->height(), $cursor, $melody, $selection, true,
        composer: Controller::composerPreview(), fixedBands: $this->editBands);
      $texture = $textures->textureFromImage($image);
      $texture->copyTo($surface, 0, 0);
      $texture->destroy();
      return;
    }
    $this->editBase->copyTo($surface, 0, 0);
    $overlay = SheetPainter::cursorOverlay($this->visibleScore, $firstBar, $columns,
      $surface->width(), $surface->height(), $cursor, $melody, $this->editBands);
    $texture = $textures->textureFromImage($overlay);
    $sdl = \SPTK\App::sdl();
    $sdl->checkReturnValue($sdl->ffi->SDL_SetTextureBlendMode($texture->handle(),
      SDL::SDL_BLENDMODE_BLEND_PREMULTIPLIED), 'SDL_SetTextureBlendMode');
    $texture->copyTo($surface, 0, 0);
    $texture->destroy();
  }

  /** A score edit invalidates the sheet cache; cursor movement only repaints its overlay. */
  public function invalidateContent(): void {
    $this->contentDirty = true;
    $this->invalidate();
  }

  /** Keep notes touching the viewport and whole chains needed for drawn links. */
  private static function visibleWindow(Score $score, int $firstBar, int $columns): Score {
    $score = $score->visibleCopy()->renderCopy();
    $start = $score->start($firstBar);
    $end = $score->start($firstBar + $columns);
    $atTime = [];
    $byMelody = [];
    $links = [];
    $keep = [];
    foreach ($score->notes as $index => $note) {
      $noteStart = $score->start($note['bar']) + $note['slot'];
      $noteEnd = $noteStart + $note['length'];
      $atTime[$note['melody'] . ':' . $noteStart] = $index;
      $byMelody[$note['melody']][] = [$noteStart, $index];
      if ($noteStart < $end && $noteEnd > $start) {
        $keep[$index] = true;
      }
    }
    $nextMelody = [];
    foreach ($byMelody as $ordered) {
      sort($ordered);
      for ($index = 0; $index + 1 < count($ordered); $index++) {
        $nextMelody[$ordered[$index][1]] = $ordered[$index + 1][1];
      }
    }
    foreach ($score->notes as $index => $note) {
      if (!($note['slurToNext'] ?? false) && !($note['glissandoToNext'] ?? false)
        && ($note['dynamicToNext'] ?? '') === '') {
        continue;
      }
      $noteStart = $score->start($note['bar']) + $note['slot'];
      $next = ($note['dynamicToNext'] ?? '') !== ''
        ? ($nextMelody[$index] ?? null)
        : ($atTime[$note['melody'] . ':' . ($noteStart + $note['length'])] ?? null);
      if ($next !== null) {
        $links[$index][] = $next;
        $links[$next][] = $index;
        $target = $score->notes[$next];
        if ($noteStart < $end && $score->start($target['bar']) + $target['slot'] + $target['length'] > $start) {
          $keep[$index] = $keep[$next] = true;
        }
      }
    }
    $queue = array_keys($keep);
    for ($head = 0; $head < count($queue); $head++) {
      foreach ($links[$queue[$head]] ?? [] as $next) {
        if (!isset($keep[$next])) {
          $keep[$next] = true;
          $queue[] = $next;
        }
      }
    }
    $copy = clone $score;
    $copy->notes = array_values(array_intersect_key($score->notes, $keep));
    $copy->scoreDynamics = array_values(array_filter($score->scoreDynamics,
      static fn (array $mark): bool => $mark['time'] < $end && ($mark['end'] ?? $mark['time'] + 1) > $start));
    return $copy;
  }

  public function preservePixels(): bool {
    return ControllerPlayback::playbackFrame() !== null;
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
