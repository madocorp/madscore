<?php

namespace MADSCORE\App;

use MADSCORE\Play\MidiPlayback;
use MADSCORE\Play\PlaybackTimeline;
use MADSCORE\Play\ScoreSource;
use MADSCORE\Play\SheetCache;
use MADSCORE\Render\SheetPainter;
use SPTK\App;
use SPTK\Events\EventContext;
use SPTK\Events\KeyNormalizer;
use SPTK\SDLWrapper\SDL;

/** Audio playback and playback input handling. */
final class ControllerPlayback extends ControllerState {
  /** Toggle playback of the score or active melody from the selection or cursor. */
  public static function togglePlayback(bool $selectedMelody): void {
    $mode = $selectedMelody ? 'melody' : 'score';
    $cache = null;
    $return = null;
    try {
      $audio = App::audio();
      if (self::$playbackMode === $mode && $audio->playingMusic()) {
        self::stopPlayback('Playback stopped.');
        return;
      }
      if (self::$playbackMode !== null) {
        self::stopPlayback(null);
      }
      if ($selectedMelody && self::$score->melodyMuted(self::$melody)) {
        ControllerNavigation::status('Melody ' . (self::$melody + 1) . ' is muted. Unmute it to play.');
        return;
      }
      $selection = ControllerSelection::selectionStateForSheet();
      $melody = $selectedMelody ? self::$melody : null;
      $start = $selection['start'] ?? self::$score->start(self::$bar) + self::$slot;
      $end = $selection['end'] ?? null;
      if (Controller::isDesign() && !ControllerSheet::commitEditorFields()) {
        return;
      }
      $timeline = new PlaybackTimeline(self::$score, $start, $end);
      $firstBeat = $timeline->beats[0];
      $firstPage = intdiv($firstBeat['bar'], self::$columns);
      $return = ['view' => self::$view, 'firstBar' => self::$firstBar];
      if (!Controller::isDesign()) {
        self::$view = 'editor';
        self::$sheet = self::$sheets['editor'];
        self::$window->setCurrentScreenId('editor');
      }
      self::$firstBar = $firstPage * self::$columns;
      self::$window->refreshLayout();
      [$width, $height] = self::$sheets['editor']->instance()->surfaceSize();
      if ($width < 1 || $height < 1) {
        throw new \RuntimeException('The score sheet has no drawable area.');
      }
      $cache = new SheetCache(self::$score->visibleCopy(), self::$columns, $width, $height,
        ControllerSelection::cursorStateForSheet(), self::$melody, $firstPage);
      $midiFailure = self::$playbackSound === 'midi' ? MidiPlayback::unavailableReason() : null;
      $midiPlayed = false;
      if (self::$playbackSound === 'midi' && $midiFailure === null) {
        try {
          $wave = MidiPlayback::render(self::$score, $melody, $start, $end);
          try {
            $audio->playMusic($wave);
          } finally {
            unlink($wave);
          }
          $midiPlayed = true;
        } catch (\Throwable $error) {
          $midiFailure = $error->getMessage();
        }
      }
      if ($midiPlayed) {
        self::beginPlayback($mode, $timeline, $cache, $return, $firstBeat, $timeline->durationSeconds);
        ControllerNavigation::status('Playing ' . ($selectedMelody ? 'melody' : 'score')
          . ($selection === null ? '' : ' selection') . ' with MIDI.');
        return;
      }
      $source = new ScoreSource(self::$score, $melody, $start, $end);
      $audio->playMusic($source);
      self::beginPlayback($mode, $timeline, $cache, $return, $firstBeat, $source->durationSeconds());
      ControllerNavigation::status('Playing ' . ($selectedMelody ? 'melody' : 'score')
        . ($selection === null ? '' : ' selection') . ' with built-in sound.'
        . ($midiFailure === null ? '' : ' MIDI: ' . $midiFailure));
    } catch (\Throwable $error) {
      if (self::$playbackFrame !== null) {
        self::stopPlayback(null);
      } else {
        $cache?->close();
        if (isset($audio)) {
          $audio->stopMusic();
        }
        if ($return !== null) {
          self::$firstBar = $return['firstBar'];
          self::$view = $return['view'];
          self::$sheet = self::$sheets[self::$view];
          self::$window->setCurrentScreenId(self::$view);
          ControllerSheet::invalidateSheet();
        }
      }
      ControllerNavigation::status('Playback failed: ' . $error->getMessage());
    }
  }

  /** Expose the current prepared page and beat to the retained canvas. */
  public static function playbackFrame(): ?array {
    return self::$playbackFrame;
  }

  /** Install the playback input guard and show the first beat. */
  private static function beginPlayback(string $mode, PlaybackTimeline $timeline, SheetCache $cache,
    array $return, array $firstBeat, float $endSeconds): void {
    self::clearPlaybackInputDrain(false);
    self::$playbackMode = $mode;
    self::$playbackTimeline = $timeline;
    self::$playbackCache = $cache;
    self::$playbackReturn = $return;
    self::$playbackBeatIndex = 0;
    self::$playbackEndSeconds = $endSeconds;
    self::showPlaybackBeat($firstBeat);
    Controller::screen('editor')->setInputGuard(self::playbackInput(...));
    ControllerSheet::invalidateSheet();
    self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
  }

  /** Move only the beat overlay, changing the page texture when needed. */
  private static function showPlaybackBeat(array $beat): void {
    $cache = self::$playbackCache;
    [$width, $height] = self::$sheets['editor']->instance()->surfaceSize();
    $page = intdiv($beat['bar'], self::$columns);
    self::$firstBar = $page * self::$columns;
    self::$playbackFrame = ['cache' => $cache, 'page' => $page, 'width' => $width,
      'height' => $height, 'rect' => SheetPainter::beatRect(self::$score, self::$firstBar,
        self::$columns, $width, $height, $beat['bar'], $beat['slot'])];
  }

  /** Follow the sound player's consumed position, allowing small output latency. */
  private static function playbackTick(): void {
    self::$playbackTimer = null;
    if (self::$playbackFrame === null) {
      return;
    }
    try {
      $position = App::audio()->musicPositionSeconds();
      if ($position === null) {
        self::stopPlayback('Playback finished.');
        return;
      }
      if ($position >= self::$playbackEndSeconds) {
        self::stopPlayback('Playback finished.');
        return;
      }
      $beatIndex = self::$playbackTimeline->beatAt($position);
      if ($beatIndex === self::$playbackBeatIndex) {
        self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
        return;
      }
      $beat = self::$playbackTimeline->beats[$beatIndex];
      $page = intdiv($beat['bar'], self::$columns);
      if ($page !== self::$playbackFrame['page'] && self::$playbackCache->page($page) === null) {
        if (($failure = self::$playbackCache->failure()) !== null) {
          self::stopPlayback('Playback stopped: ' . $failure);
          return;
        }
      } else {
        $before = self::$playbackFrame;
        self::showPlaybackBeat($beat);
        self::$playbackBeatIndex = $beatIndex;
        if ($before['page'] !== self::$playbackFrame['page'] || $before['rect'] !== self::$playbackFrame['rect']) {
          ControllerSheet::invalidateSheet();
        }
      }
    } catch (\Throwable $error) {
      self::stopPlayback('Playback stopped: ' . $error->getMessage());
      return;
    }
    self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
  }

  /** Consume editor input while playing, except stop and sound-mode switches. */
  private static function playbackInput(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_UP) {
      unset(self::$playbackHeldKeys[(int)$event->key->key]);
      return true;
    }
    if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
      return true;
    }
    self::$playbackHeldKeys[(int)$event->key->key] = true;
    if ($event->key->repeat) {
      return true;
    }
    $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
    $modifiers = KeyNormalizer::normalizeModifiers((int)$event->key->mod);
    if ($modifiers === 0 && $key === SDL::KEY_ESCAPE) {
      self::stopPlayback('Playback stopped.');
    } else if ($modifiers === 0 && in_array(($name = KeyNormalizer::keyName($key, 0)), ['p', 'm'], true)) {
      self::togglePlayback($name === 'm');
    }
    return true;
  }

  /** Restore the editor state and release prepared playback pages. */
  public static function stopPlayback(?string $message): void {
    if (self::$playbackFrame === null) {
      return;
    }
    App::audio()->stopMusic();
    if (self::$playbackTimer !== null) {
      App::eventLoop()->removeTimer(self::$playbackTimer);
      self::$playbackTimer = null;
    }
    Controller::screen('editor')->setInputGuard(null);
    self::$sheets['editor']->instance()->resetPlayback();
    self::$playbackFrame = null;
    self::$playbackTimeline = null;
    self::$playbackBeatIndex = -1;
    self::$playbackEndSeconds = 0.0;
    self::$playbackMode = null;
    self::$playbackCache?->close();
    self::$playbackCache = null;
    self::$firstBar = self::$playbackReturn['firstBar'];
    $view = self::$playbackReturn['view'];
    self::$playbackReturn = null;
    self::$view = $view;
    self::$sheet = self::$sheets[$view];
    self::$window->setCurrentScreenId($view);
    self::drainPlaybackInput($view);
    ControllerSheet::invalidateSheet();
    if ($message !== null) {
      ControllerNavigation::status($message);
    }
  }

  /** Consume input already queued at the stop boundary and held-key repeats. */
  private static function drainPlaybackInput(string $view): void {
    self::clearPlaybackInputDrain(false);
    self::$playbackDrainView = $view;
    self::$playbackDrainBatch = true;
    Controller::screen($view)->setInputGuard(self::playbackDrainGuard(...));
    self::$playbackDrainTimer = App::eventLoop()->after(1, self::finishPlaybackInputDrain(...));
  }

  /** Release a held playback key without forwarding its repeat or text. */
  private static function playbackDrainGuard(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      $key = (int)$event->key->key;
      if (self::$playbackDrainBatch) {
        self::$playbackHeldKeys[$key] = true;
        return true;
      }
      if (isset(self::$playbackHeldKeys[$key])) {
        self::$playbackSuppressText = true;
        return true;
      }
      self::$playbackSuppressText = false;
      return false;
    }
    if ($event->type === SDL::SDL_EVENT_KEY_UP && isset(self::$playbackHeldKeys[(int)$event->key->key])) {
      unset(self::$playbackHeldKeys[(int)$event->key->key]);
      if (self::$playbackHeldKeys === [] && !self::$playbackDrainBatch) {
        self::drainPlaybackInput(self::$playbackDrainView);
      }
      return true;
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT && self::$playbackSuppressText) {
      self::$playbackSuppressText = false;
      return true;
    }
    return self::$playbackDrainBatch;
  }

  /** Let fresh editor input through after SDL's current event batch is drained. */
  private static function finishPlaybackInputDrain(): void {
    self::$playbackDrainTimer = null;
    self::$playbackDrainBatch = false;
    self::$playbackSuppressText = false;
    if (self::$playbackHeldKeys === []) {
      self::clearPlaybackInputDrain();
    }
  }

  /** Remove an old drain guard before another playback starts. */
  private static function clearPlaybackInputDrain(bool $clearHeld = true): void {
    if (self::$playbackDrainTimer !== null) {
      App::eventLoop()->removeTimer(self::$playbackDrainTimer);
      self::$playbackDrainTimer = null;
    }
    if (self::$playbackDrainView !== null) {
      Controller::screen(self::$playbackDrainView)->setInputGuard(null);
      self::$playbackDrainView = null;
    }
    self::$playbackDrainBatch = false;
    if ($clearHeld) {
      self::$playbackHeldKeys = [];
    }
  }

  /** Let the user compare the built-in synthesis with MIDI SoundFont playback. */
  public static function togglePlaybackSound(EventContext $event): void {
    if (self::$playbackMode !== null) {
      self::stopPlayback(null);
    }
    self::$playbackSound = self::$playbackSound === 'midi' ? 'built-in' : 'midi';
    Controller::screen('settings')->widget('playbackSound')->setLabel('Playback: '
      . (self::$playbackSound === 'midi' ? 'MIDI' : 'Built-in'));
    self::$window->refreshLayout();
    ControllerNavigation::status('Playback sound: ' . (self::$playbackSound === 'midi' ? 'MIDI' : 'built-in') . '.', 'settings');
  }

  /** Start or stop all melodies from an editor screen shortcut. */
  public static function toggleScorePlayback(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::togglePlayback(false);
    }
    return true;
  }

  /** Start or stop the active melody from an editor screen shortcut. */
  public static function toggleMelodyPlayback(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::togglePlayback(true);
    }
    return true;
  }
}
