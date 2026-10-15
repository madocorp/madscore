<?php

namespace MADSCORE;

use SPTK\Audio\Source;
use SPTK\SDLWrapper\SDL;

/** Renders a frozen score range using each melody's harmonic pattern. */
final class ScoreSource implements Source {

  public const SAMPLE_RATE = 48000;
  private const ATTACK_FRAMES = 240;
  private const RELEASE_FRAMES = 720;
  private const EDGE_FRAMES = 240;

  private array $events = [];
  private array $active = [];
  private int $nextEvent = 0;
  private int $frame = 0;
  private int $totalFrames;

  /** Freeze the requested half-beat span and optional melody from a score. */
  public function __construct(Score $score, ?int $melody = null, ?int $start = null, ?int $end = null) {
    $wholeScore = $end === null;
    $scoreEnd = $score->start(count($score->bars));
    $start ??= 0;
    $end ??= $scoreEnd;
    if ($start < 0 || $end > $scoreEnd || $start >= $end || ($melody !== null && ($melody < 0 || $melody >= Score::MELODY_COUNT))) {
      throw new \InvalidArgumentException('Invalid score playback range or melody.');
    }
    $barStarts = [0];
    $seconds = [0.0];
    foreach ($score->bars as $bar) {
      $barStarts[] = end($barStarts) + 2 * $bar['beats'];
      $seconds[] = end($seconds) + $bar['beats'] * 60.0 / (float)$bar['tempo'];
    }
    $rangeStart = self::secondsAt($start, $barStarts, $seconds);
    $rangeEnd = self::secondsAt($end, $barStarts, $seconds);
    $this->totalFrames = max(1, (int)round(($rangeEnd - $rangeStart) * self::SAMPLE_RATE));
    foreach ($score->notes as $note) {
      if ($melody !== null && $note['melody'] !== $melody) {
        continue;
      }
      $settings = $score->melodies[$note['melody']];
      if ($settings['volume'] === 0) {
        continue;
      }
      $noteStart = $score->start($note['bar']) + $note['slot'];
      $noteEnd = $noteStart + $note['length'];
      $drum = in_array($settings['pattern'], Drum::NAMES, true);
      if ($noteStart >= $end || $noteEnd <= $start) {
        continue;
      }
      $onset = (int)round((self::secondsAt($noteStart, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
      if ($drum) {
        if ($onset < 0) {
          continue;
        }
        $ending = $onset + (int)round(Drum::duration($settings['pattern']) * self::SAMPLE_RATE);
        if ($wholeScore) {
          $this->totalFrames = max($this->totalFrames, $ending);
        }
        $this->events[] = [
          'start' => $onset, 'end' => min($this->totalFrames, $ending),
          'onset' => $onset, 'drum' => $settings['pattern'], 'volume' => $settings['volume'] / 100,
        ];
        continue;
      }
      $ending = (int)round((self::secondsAt($noteEnd, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
      if ($ending <= $onset) {
        continue;
      }
      $semitones = ($note['octave'] - 4) * 12 + Pitch::position($note) - 1;
      $frequency = 440.0 * 2 ** ($semitones / 12);
      $this->events[] = [
        'start' => max(0, $onset), 'end' => min($this->totalFrames, $ending),
        'onset' => $onset, 'ending' => $ending,
        'frequency' => $frequency,
        'partials' => Waveform::partials($settings['pattern'], $frequency, self::SAMPLE_RATE),
        'volume' => $settings['volume'] / 100,
      ];
    }
    usort($this->events, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
  }

  public function spec(): array {
    return ['format' => SDL::SDL_AUDIO_F32LE, 'channels' => 1, 'freq' => self::SAMPLE_RATE];
  }

  /** Produce little-endian float PCM for the next portion of the score. */
  public function read(int $frames): string {
    if ($frames < 1) {
      throw new \InvalidArgumentException('Frame count must be positive.');
    }
    $end = min($this->totalFrames, $this->frame + $frames);
    $pcm = '';
    for (; $this->frame < $end; $this->frame++) {
      while ($this->nextEvent < count($this->events) && $this->events[$this->nextEvent]['start'] <= $this->frame) {
        $this->active[] = $this->events[$this->nextEvent++];
      }
      $sum = 0.0;
      $sounding = 0;
      foreach ($this->active as $index => $event) {
        if ($event['end'] <= $this->frame) {
          unset($this->active[$index]);
          continue;
        }
        if (isset($event['drum'])) {
          $sum += Drum::sample($event['drum'], $this->frame - $event['onset'], self::SAMPLE_RATE) * $event['volume'];
          $sounding++;
          continue;
        }
        $attack = min(1.0, max(0.0, ($this->frame - $event['onset']) / self::ATTACK_FRAMES));
        $release = min(1.0, max(0.0, ($event['ending'] - $this->frame) / self::RELEASE_FRAMES));
        $phase = 2 * M_PI * $event['frequency'] * ($this->frame - $event['onset']) / self::SAMPLE_RATE;
        $wave = 0.0;
        foreach ($event['partials'] as [$multiple, $gain]) {
          $wave += $gain * sin($multiple * $phase);
        }
        $cutStart = $event['onset'] < 0 ? min(1.0, ($this->frame + 1) / self::EDGE_FRAMES) : 1.0;
        $sum += $wave * $event['volume'] * min($attack, $release) * $cutStart;
        $sounding++;
      }
      $edge = min(1.0, ($this->totalFrames - $this->frame) / self::EDGE_FRAMES);
      $sample = $sounding > 0 ? $sum * $edge * 0.32 / sqrt($sounding) : 0.0;
      $pcm .= pack('g', max(-0.95, min(0.95, $sample)));
    }
    return $pcm;
  }

  public function rewind(): void {
    $this->active = [];
    $this->nextEvent = 0;
    $this->frame = 0;
  }

  /** Convert a half-beat position through each bar's BPM. */
  private static function secondsAt(int $position, array $barStarts, array $seconds): float {
    $last = count($barStarts) - 1;
    if ($position === $barStarts[$last]) {
      return $seconds[$last];
    }
    for ($bar = 0; $bar < $last; $bar++) {
      if ($position < $barStarts[$bar + 1]) {
        $halfBeatSeconds = ($seconds[$bar + 1] - $seconds[$bar]) / ($barStarts[$bar + 1] - $barStarts[$bar]);
        return $seconds[$bar] + ($position - $barStarts[$bar]) * $halfBeatSeconds;
      }
    }
    throw new \OutOfBoundsException('Position is outside the score.');
  }

}
