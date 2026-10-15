<?php

namespace MADSCORE;

use SPTK\SDLWrapper\SDL;
use SPTK\Events\{EventContext, KeyNormalizer};

/** Maps keyboard commands to the note preview, time, octave, and melody. */
final class EditorInput {

  /** Handle a key or printable symbol on the active score sheet. */
  public static function handle(SheetCanvas $canvas, mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      return self::key($canvas, $event);
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT) {
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
    $context = new EventContext('keyDown', $canvas, $event);
    if (($key === ord('p') || $key === ord('P')) && !$ctrl && !$alt && !$event->key->repeat) {
      Controller::togglePlayback($shift);
      return true;
    }
    if ($key === SDL::KEY_F3) {
      Controller::showFiles($context);
      return true;
    }
    if ($key === SDL::KEY_F5) {
      Controller::newBar($context);
      return true;
    }
    if ($key === SDL::KEY_F6) {
      Controller::save($context);
      return true;
    }
    if ($key === SDL::KEY_F7) {
      Controller::exportPdf($context);
      return true;
    }
    if ($key === SDL::KEY_F8) {
      Controller::toggleSection($context);
      return true;
    }
    if ($key === SDL::KEY_LEFT || $key === SDL::KEY_RIGHT) {
      Controller::moveTime($key === SDL::KEY_LEFT ? -1 : 1, $ctrl, $shift && !$ctrl);
      return true;
    }
    if ($key === SDL::KEY_HOME || $key === SDL::KEY_END) {
      Controller::moveToVisibleEdge($key === SDL::KEY_END);
      return true;
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
    if ($key === SDL::KEY_TAB) {
      Controller::moveMelody($shift ? -1 : 1);
      return true;
    }
    if ($key === SDL::KEY_BACKSPACE || $key === SDL::KEY_SPACE) {
      try {
        Controller::setCursorLength(Controller::cursorLength() + ($key === SDL::KEY_SPACE ? 1 : -1));
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_RETURN) {
      try {
        Controller::dropNote();
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_INSERT) {
      try {
        Controller::insertAfter($shift);
      } catch (\DomainException $error) {
        Controller::status($error->getMessage());
      }
      return true;
    }
    if ($key === SDL::KEY_DELETE) {
      try {
        Controller::deleteAtCursor();
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
    $symbol = strtoupper($value);
    try {
      if (str_contains('ABCDEFG', $symbol)) {
        Controller::setPitchLetter($symbol);
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
