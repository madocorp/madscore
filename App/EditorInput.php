<?php

namespace MADSCORE\App;

use SPTK\SDLWrapper\SDL;
use SPTK\Events\{EventContext, KeyNormalizer};
use SPTK\Widgets\Button\Button;

/** Maps keyboard commands to the note preview, time, octave, and melody. */
final class EditorInput {

  /** Handle a key or printable symbol on the active score sheet. */
  public static function handle(SheetCanvas $canvas, mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      return self::key($canvas, $event);
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT && Controller::isDesign()) {
      return self::text(\FFI::string($event->text->text));
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
    if ($ctrl && !$alt && !$shift && !$mode && !$event->key->repeat) {
      $name = KeyNormalizer::keyName($key, 0);
      if ($name === 'z' || $name === 'y') {
        $name === 'z' ? Controller::undoMelody() : Controller::redoMelody();
        return true;
      }
    }
    if (!Controller::isDesign()) {
      $name = KeyNormalizer::keyName($key, (int)$event->key->mod);
      if (in_array($name, ['p', 'm'], true) && !$ctrl && !$alt && !$shift && !$event->key->repeat) {
        Controller::togglePlayback($name === 'm');
      } else if (($name === 'v' || $key === SDL::KEY_ESCAPE || $key === SDL::KEY_RETURN)
        && !$ctrl && !$alt && !$shift && !$event->key->repeat) {
        Controller::toggleView($context);
      } else if ($key === SDL::KEY_PAGEUP) {
        Controller::scrollRows(-1);
      } else if ($key === SDL::KEY_PAGEDOWN) {
        Controller::scrollRows(1);
      } else if ($key === SDL::KEY_HOME || $key === SDL::KEY_END) {
        Controller::previewEdge($key === SDL::KEY_END);
      }
      return true;
    }
    if (KeyNormalizer::normalizeModifiers((int)$event->key->mod) === 0) {
      $name = KeyNormalizer::keyName($key, 0);
      if ($name === 'n') {
        if (!$event->key->repeat) {
          Controller::addMelody($context);
        }
        return true;
      }
      if ($name === 'u') {
        if (!$event->key->repeat) {
          try {
            Controller::duplicateNote();
          } catch (\DomainException $error) {
            Controller::status($error->getMessage());
          }
        }
        return true;
      }
      if ($name === 'r' || $name === 'y' || $name === 't') {
        if (!$event->key->repeat) {
          Controller::selectSettings(match ($name) {
            'r' => 'tempo',
            'y' => 'sound',
            't' => 'staccato',
          });
        }
        return true;
      }
      if ($name === 'v') {
        if (!$event->key->repeat) {
          Controller::toggleView($context);
        }
        return true;
      }
      if ($name === 'p' || $name === 'm') {
        if (!$event->key->repeat) {
          Controller::togglePlayback($name === 'm');
        }
        return true;
      }
    }
    if ($key === SDL::KEY_TAB && !$ctrl && !$alt) {
      Controller::moveMelody($shift ? -1 : 1);
      return true;
    }
    if ($ctrl || $alt || $mode) {
      return false;
    }
    if ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT) {
      Controller::moveTime($key === SDL::KEY_LEFT ? -1 : 1, $shift);
      return true;
    }
    if ($key === SDL::KEY_HOME || $key === SDL::KEY_END) {
      Controller::moveToVisibleEdge($key === SDL::KEY_END, $shift);
      return true;
    }
    if ($shift) {
      return false;
    }
    if ($key === SDL::KEY_UP || $key === SDL::KEY_DOWN) {
      try {
        Controller::stepPitch($key === SDL::KEY_UP ? 1 : -1);
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_PAGEUP || $key === SDL::KEY_PAGEDOWN) {
      try {
        Controller::moveOctave($key === SDL::KEY_PAGEUP ? -1 : 1);
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_SPACE || $key === SDL::KEY_RETURN) {
      try {
        if ($key === SDL::KEY_RETURN) {
          Controller::dropNote();
        } else {
          Controller::followNotePitch();
        }
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_INSERT) {
      try {
        Controller::insertInverseBeat();
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_BACKSPACE || $key === SDL::KEY_DELETE) {
      try {
        if ($key === SDL::KEY_BACKSPACE) {
          Controller::deleteBeforeCursor();
        } else {
          Controller::deleteAtCursor();
        }
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    return false;
  }

  /** Set preview pitch or width through printable keys. */
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
    $symbol = strtoupper($value);
    try {
      if (str_contains('ABCDEFG', $symbol)) {
        Controller::setPitchLetter($symbol);
      } else if ($value === '+' || $value === '-') {
        Controller::setCursorLength(Controller::cursorLength() + ($value === '+' ? 1 : -1));
      } else if ($value >= '0' && $value <= '9') {
        Controller::setCursorLength($value === '0' ? 1 : (int)$value * 2);
      } else {
        return false;
      }
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
    }
    return true;
  }

}
