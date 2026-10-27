<?php

namespace MADSCORE\App;

use SPTK\SDLWrapper\SDL;
use SPTK\Events\{EventContext, KeyNormalizer};
use SPTK\Widgets\Button\Button;

/** Maps keyboard commands to the note preview, time, octave, and melody. */
final class EditorInput {

  private static bool $suppressWedgeText = false;
  private static int $lastKeyModifiers = 0;

  public static function suppressNextWedgeText(): void {
    self::$suppressWedgeText = true;
  }

  /** Handle a key or printable symbol on the active score sheet. */
  public static function handle(SheetCanvas $canvas, mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      self::$suppressWedgeText = false;
      self::$lastKeyModifiers = KeyNormalizer::normalizeModifiers((int)$event->key->mod);
      return self::key($canvas, $event);
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT) {
      $value = \FFI::string($event->text->text);
      $modifiers = self::$lastKeyModifiers;
      self::$lastKeyModifiers = 0;
      if (self::$suppressWedgeText && $value === '*') {
        self::$suppressWedgeText = false;
        return true;
      }
      self::$suppressWedgeText = false;
      if (($modifiers & SDL::MOD_CTRL) !== 0
        && ($modifiers & (SDL::MOD_ALT | SDL::MOD_MODE)) === 0 && $value === '*') {
        try {
          ControllerNoteMarks::cycleDynamic();
        } catch (\DomainException $error) {
          ControllerNavigation::status($error->getMessage());
        }
        return true;
      }
      if (($modifiers & (SDL::MOD_CTRL | SDL::MOD_ALT | SDL::MOD_MODE)) !== 0
        && $value >= '1' && $value <= '9') {
        return true;
      }
      return self::text($value);
    }
    return false;
  }

  /** Navigate or delete through non-text keys. */
  private static function key(SheetCanvas $canvas, mixed $event): bool {
    $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
    $shift = ((int)$event->key->mod & SDL::MOD_SHIFT) !== 0;
    $ctrl = ((int)$event->key->mod & SDL::MOD_CTRL) !== 0;
    $alt = ((int)$event->key->mod & SDL::MOD_ALT) !== 0;
    $mode = ((int)$event->key->mod & SDL::MOD_MODE) !== 0;
    $context = new EventContext('keyDown', $canvas, $event);
    if ($shift && !$ctrl && !$alt && !$mode
      && KeyNormalizer::keyName($key, 0) === 'c') {
      if (!$event->key->repeat) {
        ControllerMotifGeneration::regenerateComposerFromSheet();
      }
      return true;
    }
    if (!$shift && !$ctrl && !$alt && !$mode
      && KeyNormalizer::keyName($key, 0) === 'c') {
      if (!$event->key->repeat) {
        ControllerComposer::openComposer();
      }
      return true;
    }
    if ($ctrl && !$alt && !$shift && !$mode && !$event->key->repeat) {
      $name = KeyNormalizer::keyName($key, 0);
      if ($name >= '1' && $name <= '9') {
        try {
          ControllerNoteMarks::setDynamicLevel(self::digitLevel($name), false);
        } catch (\DomainException $error) {
          ControllerNavigation::status($error->getMessage());
        }
        return true;
      }
      if ($name === 'a') {
        ControllerSelection::selectAll();
        return true;
      }
      if ($name === 'z' || $name === 'y') {
        $name === 'z' ? ControllerNoteEditing::undoScore() : ControllerNoteEditing::redoScore();
        return true;
      }
      if ($name === 'c' || $name === 'v') {
        try {
          $name === 'c' ? ControllerSelection::copySelection() : ControllerSelection::pasteSelection();
        } catch (\DomainException $error) {
          ControllerNavigation::status($error->getMessage());
        }
        return true;
      }
    }
    if (KeyNormalizer::normalizeModifiers((int)$event->key->mod) === 0) {
      $name = KeyNormalizer::keyName($key, 0);
      if ($name === 'n') {
        if (!$event->key->repeat) {
          ControllerNavigation::addMelody($context);
        }
        return true;
      }
      if ($name === 'u') {
        if (!$event->key->repeat) {
          try {
            ControllerNoteMarks::duplicateNote();
          } catch (\DomainException $error) {
            ControllerNavigation::status($error->getMessage());
          }
        }
        return true;
      }
      if ($name === 'r' || $name === 'y' || $name === 't') {
        if (!$event->key->repeat) {
          ControllerNavigation::selectSettings(match ($name) {
            'r' => 'tempo',
            'y' => 'sound',
            't' => 'figure',
          });
        }
        return true;
      }
      if ($name === 'c') {
        if (!$event->key->repeat) {
          ControllerComposer::openComposer();
        }
        return true;
      }
      if ($name === 'v') {
        if (!$event->key->repeat) {
          ControllerNavigation::toggleView($context);
        }
        return true;
      }
      if ($name === 'p' || $name === 'm') {
        if (!$event->key->repeat) {
          ControllerPlayback::togglePlayback($name === 'm');
        }
        return true;
      }
    }
    if ($key === SDL::KEY_TAB && !$ctrl && !$alt) {
      ControllerNavigation::moveMelody($shift ? -1 : 1);
      return true;
    }
    if ($ctrl && $shift && !$alt && !$mode) {
      if ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT) {
        ControllerSelection::moveScoreSelection($key === SDL::KEY_LEFT ? -1 : 1);
        return true;
      }
      if ($key === SDL::KEY_HOME || $key === SDL::KEY_END) {
        ControllerNavigation::moveToVisibleEdge($key === SDL::KEY_END, true, 'score');
        return true;
      }
    }
    if ($ctrl && !$shift && !$alt && !$mode && ($key === SDL::KEY_HOME || $key === SDL::KEY_END)) {
      ControllerSelection::moveToScoreEdge($key === SDL::KEY_END);
      return true;
    }
    if ($ctrl || $alt || $mode) {
      return false;
    }
    if ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT) {
      ControllerNavigation::moveTime($key === SDL::KEY_LEFT ? -1 : 1, $shift);
      return true;
    }
    if ($key === SDL::KEY_HOME || $key === SDL::KEY_END) {
      ControllerNavigation::moveToVisibleEdge($key === SDL::KEY_END, $shift);
      return true;
    }
    if ($shift && ($key === SDL::KEY_SPACE || $key === SDL::KEY_RETURN)) {
      try {
        ControllerNoteEditing::dropNote(false, true);
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($shift && $key === SDL::KEY_DELETE) {
      try {
        ControllerNoteMarks::deleteToneAtCursor();
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($shift) {
      return false;
    }
    if ($key === SDL::KEY_UP || $key === SDL::KEY_DOWN) {
      try {
        if (ControllerSelection::selectionStateForSheet() !== null) {
          ControllerSelection::transposeSelection($key === SDL::KEY_UP ? 1 : -1);
        } else {
          ControllerNoteEditing::stepPitch($key === SDL::KEY_UP ? 1 : -1);
        }
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_PAGEUP || $key === SDL::KEY_PAGEDOWN) {
      try {
        if (ControllerSelection::selectionStateForSheet() !== null) {
          ControllerSelection::transposeSelection($key === SDL::KEY_PAGEUP ? 12 : -12);
        } else {
          ControllerNavigation::moveOctave($key === SDL::KEY_PAGEUP ? -1 : 1);
        }
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_SPACE || $key === SDL::KEY_RETURN) {
      try {
        if ($key === SDL::KEY_RETURN) {
          ControllerNoteEditing::dropNote(true);
        } else {
          ControllerNoteEditing::followNotePitch();
        }
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_INSERT) {
      try {
        ControllerNoteMarks::insertInverseBeat();
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_BACKSPACE || $key === SDL::KEY_DELETE) {
      try {
        if ($key === SDL::KEY_BACKSPACE) {
          ControllerNoteMarks::deleteBeforeCursor();
        } else {
          ControllerNoteMarks::deleteAtCursor();
        }
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
      }
      return true;
    }
    return false;
  }

  /** Apply sheet shortcuts from printable keys. */
  private static function text(string $value): bool {
    if (strlen($value) !== 1) {
      return false;
    }
    foreach (Controller::screen('editor')->layout->allLeaves() as $leaf) {
      $button = $leaf->instance();
      if ($button instanceof Button && $button->hotkey() === $value) {
        $button->press();
        return true;
      }
    }
    try {
      if ($value === '+' || $value === '-') {
        ControllerNoteEditing::setCursorLength(ControllerNoteEditing::cursorLength() + ($value === '+' ? 1 : -1));
      } else if ($value >= '1' && $value <= '9') {
        ControllerNoteMarks::setDynamicLevel(self::digitLevel($value), true);
      } else {
        return false;
      }
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
    }
    return true;
  }

  private static function digitLevel(string $digit): string {
    return ['ppp', 'pp', 'p', 'mp', '', 'mf', 'f', 'ff', 'fff'][(int)$digit - 1];
  }

}
