<?php

namespace MADSCORE\Play;

use MADSCORE\Score\DynamicLevel;
use MADSCORE\Score\Dynamics;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Score;

use SPTK\Audio\Source;
use SPTK\SDLWrapper\SDL;

/** Renders a frozen score range using each melody's harmonic pattern. */
final class ScoreSource implements Source {

  public const SAMPLE_RATE = 48000;
  private const ATTACK_FRAMES = 240;
  private const RELEASE_FRAMES = 720;
  private const EDGE_FRAMES = 240;
  private const ORDINARY_GAP_SECONDS = 0.030;
  private const LEGATO_OVERLAP_SECONDS = 0.010;
  private const FERMATA_STRETCH = 1.5;

  private array $events = [];
  private array $active = [];
  private int $nextEvent = 0;
  private int $frame = 0;
  private int $totalFrames;
  private int $sharedCacheFrame = -1;
  private array $sharedGainCache = [];

  /** Freeze the requested half-beat span and optional melody from a score. */
  public function __construct(Score $score, ?int $melody = null, ?int $start = null, ?int $end = null) {
    $wholeScore = $start === null && $end === null;
    $scoreEnd = $score->start(count($score->bars));
    $start ??= 0;
    $end ??= $scoreEnd;
    if ($start < 0 || $end > $scoreEnd || $start >= $end || ($melody !== null && !isset($score->melodies[$melody]))) {
      throw new \InvalidArgumentException('Invalid score playback range or melody.');
    }
    $score = $score->visibleCopy();
    $barStarts = [0];
    $seconds = [0.0];
    foreach ($score->bars as $bar) {
      $barStarts[] = end($barStarts) + 2 * $bar['beats'];
      $seconds[] = end($seconds) + $bar['beats'] * 60.0 / (float)$bar['tempo'];
    }
    $rangeStart = self::secondsAt($start, $barStarts, $seconds);
    $rangeEnd = self::secondsAt($end, $barStarts, $seconds);
    $this->totalFrames = max(1, (int)round(($rangeEnd - $rangeStart) * self::SAMPLE_RATE));
    $dynamicLevels = DynamicLevel::playbackLevels($score);
    [$levels, $dynamics, $localTimes] = self::dynamicPlayback($score, $barStarts, $seconds, $rangeStart, $dynamicLevels);
    $shared = self::sharedMarks($score, $barStarts, $seconds, $rangeStart, $dynamicLevels);
    $glissandoEnds = [];
    foreach ($score->notes as $index => $note) {
      if (($note['glissandoToNext'] ?? false) && ($next = $score->nextAdjacent($index)) !== null) {
        $glissandoEnds[$next] = true;
      }
    }
    foreach ($score->notes as $index => $note) {
      if (isset($glissandoEnds[$index])) {
        continue;
      }
      if (($melody !== null && $note['melody'] !== $melody) || $score->melodyMuted($note['melody'])) {
        continue;
      }
      $settings = $score->melodies[$note['melody']];
      if ($settings['volume'] === 0) {
        continue;
      }
      if ($levels[$index] == 0.0 && !isset($dynamics[$index]) && $shared === []) {
        continue;
      }
      $noteStart = $score->start($note['bar']) + $note['slot'];
      $glissandoNext = ($note['glissandoToNext'] ?? false) ? $score->nextAdjacent($index) : null;
      $target = $glissandoNext === null ? null : $score->notes[$glissandoNext];
      $noteEnd = $target === null ? $noteStart + $note['length']
        : $score->start($target['bar']) + $target['slot'] + $target['length'];
      $drum = in_array($settings['pattern'], Drum::NAMES, true);
      if ($noteStart >= $end) {
        continue;
      }
      $onset = (int)round((self::secondsAt($noteStart, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
      if ($drum) {
        if ($onset < 0 || $noteEnd <= $start) {
          continue;
        }
        $ending = $onset + (int)round(Drum::duration($settings['pattern']) * self::SAMPLE_RATE);
        if ($wholeScore) {
          $this->totalFrames = max($this->totalFrames, $ending);
        }
        $this->events[] = [
          'start' => $onset, 'end' => min($this->totalFrames, $ending),
          'onset' => $onset, 'drum' => $settings['pattern'], 'volume' => isset($dynamics[$index]) ? 1.0 : $levels[$index],
          'dynamic' => $dynamics[$index] ?? null, 'baseVolume' => $settings['volume'] / 100,
          'localTime' => $localTimes[$index], 'shared' => $shared, 'shift' => 0,
        ];
        continue;
      }
      if ($target !== null) {
        $this->addGlissandoEvents($note, $target, $noteStart, $noteEnd, $settings['pattern'],
          isset($dynamics[$index]) ? 1.0 : $levels[$index], $dynamics[$index] ?? null,
          $barStarts, $seconds, $rangeStart, $settings['volume'] / 100, $localTimes[$index], $shared);
        continue;
      }
      $writtenEnd = self::secondsAt($noteEnd, $barStarts, $seconds);
      $writtenStart = self::secondsAt($noteStart, $barStarts, $seconds);
      $duration = $writtenEnd - $writtenStart;
      if ($note['fermata'] ?? false) {
        $soundingEnd = $writtenStart + $duration * self::FERMATA_STRETCH;
      } else if (($note['articulation'] ?? 'ordinary') === 'staccato') {
        $soundingEnd = $writtenStart + $duration * 0.5;
      } else if (($note['slurToNext'] ?? false) && $score->nextAdjacent($index) !== null) {
        $soundingEnd = $writtenEnd + self::LEGATO_OVERLAP_SECONDS;
      } else {
        $soundingEnd = $writtenEnd - min(self::ORDINARY_GAP_SECONDS, $duration * 0.2);
      }
      $ending = (int)round(($soundingEnd - $rangeStart) * self::SAMPLE_RATE);
      if ($ending <= max(0, $onset)) {
        continue;
      }
      if ($wholeScore) {
        $this->totalFrames = max($this->totalFrames, $ending);
      }
      $trill = $note['trill'] ?? 0;
      $pulses = $trill === 0 ? 1 : 2 * $note['length'];
      for ($pulse = 0; $pulse < $pulses; $pulse++) {
        $pulseStart = $trill === 0 ? $noteStart : $noteStart + $pulse / 2;
        $pulseOnset = (int)round((self::secondsAt($pulseStart, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
        $pulseEnding = $pulse === $pulses - 1 ? $ending : min($ending,
          (int)round((self::secondsAt($pulseStart + 0.5, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE));
        if ($pulseEnding <= max(0, $pulseOnset)) {
          continue;
        }
        $semitones = ($note['octave'] - 4) * 12 + Pitch::position($note) - 1
          + ($pulse % 2 === 1 ? $trill : 0);
        $frequency = 440.0 * 2 ** ($semitones / 12);
        $this->events[] = [
          'start' => max(0, $pulseOnset), 'end' => min($this->totalFrames, $pulseEnding),
          'onset' => $pulseOnset, 'ending' => $pulseEnding,
          'frequency' => $frequency,
          'partials' => Waveform::partials($settings['pattern'], $frequency, self::SAMPLE_RATE),
          'volume' => isset($dynamics[$index]) ? 1.0 : $levels[$index],
          'dynamic' => $dynamics[$index] ?? null, 'baseVolume' => $settings['volume'] / 100,
          'localTime' => $localTimes[$index], 'shared' => $shared, 'shift' => 0,
        ];
      }
    }
    if ($wholeScore) {
      $this->expandSectionRepeats($score, $seconds);
    }
    usort($this->events, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
  }

  /** Give every white key from the first pitch through the last an equal written duration. */
  private function addGlissandoEvents(array $note, array $target, int $start, int $end, string $pattern,
    float $volume, ?array $dynamic, array $barStarts, array $seconds, float $rangeStart,
    float $baseVolume, int $localTime, array $shared): void {
    $keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    $first = 7 * $note['octave'] + array_search($note['pitch'], $keys, true);
    $last = 7 * $target['octave'] + array_search($target['pitch'], $keys, true);
    $direction = $last > $first ? 1 : -1;
    $count = abs($last - $first) + 1;
    for ($index = 0; $index < $count; $index++) {
      $key = $first + $index * $direction;
      $position = $start + ($end - $start) * $index / $count;
      $nextPosition = $start + ($end - $start) * ($index + 1) / $count;
      $onset = (int)round((self::secondsAt($position, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
      $ending = (int)round((self::secondsAt($nextPosition, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE);
      if ($ending <= max(0, $onset) || $onset >= $this->totalFrames) {
        continue;
      }
      $octave = intdiv($key, 7);
      $semitones = ($octave - 4) * 12 + Pitch::naturalPosition($keys[$key % 7]) - 1;
      $frequency = 440.0 * 2 ** ($semitones / 12);
      $this->events[] = [
        'start' => max(0, $onset), 'end' => min($this->totalFrames, $ending),
        'onset' => $onset, 'ending' => $ending,
        'frequency' => $frequency,
        'partials' => Waveform::partials($pattern, $frequency, self::SAMPLE_RATE),
        'volume' => $volume, 'dynamic' => $dynamic, 'baseVolume' => $baseVolume,
        'localTime' => $localTime, 'shared' => $shared, 'shift' => 0,
      ];
    }
  }

  /** Copy each section into playback once plus its marked extra plays. */
  private function expandSectionRepeats(Score $score, array $seconds): void {
    $leaders = [];
    foreach ($score->bars as $bar => $settings) {
      if ($settings['sectionLeader']) {
        $leaders[] = $bar;
      }
    }
    if (array_sum(array_map(static fn (int $bar): int => $score->bars[$bar]['repeats'], $leaders)) === 0) {
      return;
    }
    $leaders[] = count($score->bars);
    $original = $this->events;
    $expanded = [];
    $extraFrames = 0;
    for ($section = 0; $section < count($leaders) - 1; $section++) {
      $first = $leaders[$section];
      $begin = (int)round($seconds[$first] * self::SAMPLE_RATE);
      $finish = (int)round($seconds[$leaders[$section + 1]] * self::SAMPLE_RATE);
      $duration = $finish - $begin;
      $repeats = $score->bars[$first]['repeats'];
      for ($pass = 0; $pass <= $repeats; $pass++) {
        $shift = $extraFrames + $pass * $duration;
        $jump = $pass < $repeats;
        foreach ($original as $event) {
          if ($event['onset'] < $begin || $event['onset'] >= $finish) {
            continue;
          }
          $copy = $event;
          $copy['start'] += $shift;
          $copy['onset'] += $shift;
          $copy['end'] = min($event['end'], $jump ? $finish : $this->totalFrames) + $shift;
          if (isset($copy['ending'])) {
            $copy['ending'] = min($copy['ending'], $jump ? $finish : $this->totalFrames) + $shift;
          }
          if ($copy['dynamic'] !== null) {
            $copy['dynamic']['start'] += $shift;
            $copy['dynamic']['end'] += $shift;
          }
          $copy['localTime'] += $shift;
          $copy['shift'] += $shift;
          if ($copy['end'] > $copy['start']) {
            $expanded[] = $copy;
          }
        }
      }
      $extraFrames += $repeats * $duration;
    }
    $this->events = $expanded;
    $this->totalFrames += $extraFrames;
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
          $sum += Drum::sample($event['drum'], $this->frame - $event['onset'], self::SAMPLE_RATE)
            * $event['baseVolume'] * $this->eventGain($event, $this->frame);
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
        $sum += $wave * $event['baseVolume'] * $this->eventGain($event, $this->frame)
          * min($attack, $release) * $cutStart;
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
    $this->sharedCacheFrame = -1;
    $this->sharedGainCache = [];
  }

  /** Prepare each melody's persistent levels and note-to-note ramps. */
  private static function dynamicPlayback(Score $score, array $barStarts, array $seconds, float $rangeStart,
    array $dynamicLevels): array {
    $groups = [];
    foreach (Dynamics::groups($score) as $group) {
      $groups[$group['first']] = $group;
    }
    $levels = [];
    $ramps = [];
    $localTimes = [];
    $endTargets = [];
    foreach ($score->melodies as $melody => $settings) {
      $indices = [];
      foreach ($score->notes as $index => $note) {
        if ($note['melody'] === $melody) {
          $indices[] = $index;
        }
      }
      usort($indices, static fn (int $a, int $b): int =>
        ($score->start($score->notes[$a]['bar']) + $score->notes[$a]['slot'])
        <=> ($score->start($score->notes[$b]['bar']) + $score->notes[$b]['slot']));
      $current = 1.0;
      $lastLocalTime = PHP_INT_MIN;
      foreach ($indices as $index) {
        $note = $score->notes[$index];
        $noteTime = (int)round((self::secondsAt($score->start($note['bar']) + $note['slot'], $barStarts, $seconds)
          - $rangeStart) * self::SAMPLE_RATE);
        if (isset($endTargets[$index])) {
          $current = $endTargets[$index];
        }
        if (($note['dynamicLevel'] ?? '') !== '') {
          $current = $dynamicLevels[$note['dynamicLevel']] ?? DynamicLevel::percent($note['dynamicLevel']) / 100;
          $lastLocalTime = $noteTime;
        }
        if (isset($groups[$index])) {
          $lastLocalTime = $noteTime;
        }
        $levels[$index] = $current;
        $localTimes[$index] = $lastLocalTime;
        if (!isset($groups[$index])) {
          continue;
        }
        $group = $groups[$index];
        $anchorOffset = 0;
        $anchorPosition = $group['start'];
        $anchorLevel = $current;
        foreach ($group['indices'] as $offset => $groupIndex) {
          if ($offset === 0) {
            continue;
          }
          $point = $score->notes[$groupIndex];
          $mark = $point['dynamicLevel'] ?? '';
          if ($mark === '' && $groupIndex !== $group['last']) {
            continue;
          }
          $position = $score->start($point['bar']) + $point['slot'];
          $target = $mark !== '' ? ($dynamicLevels[$mark] ?? DynamicLevel::percent($mark) / 100)
            : $dynamicLevels[$group['direction'] === 'crescendo' ? 'fff' : 'ppp'];
          $ramp = [
            'start' => (int)round((self::secondsAt($anchorPosition, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE),
            'end' => (int)round((self::secondsAt($position, $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE),
            'from' => $anchorLevel, 'to' => $target,
          ];
          for ($part = $anchorOffset; $part <= $offset; $part++) {
            $ramps[$group['indices'][$part]] = $ramp;
          }
          $anchorOffset = $offset;
          $anchorPosition = $position;
          $anchorLevel = $target;
        }
        $endTargets[$group['last']] = $anchorLevel;
      }
    }
    return [$levels, $ramps, $localTimes];
  }

  /** Interpolate the written volume level until the closing note begins. */
  private static function dynamicGain(?array $dynamic, int $frame): float {
    if ($dynamic === null) {
      return 1.0;
    }
    $progress = max(0.0, min(1.0,
      ($frame - $dynamic['start']) / max(1, $dynamic['end'] - $dynamic['start'])));
    return $dynamic['from'] + ($dynamic['to'] - $dynamic['from']) * $progress;
  }

  private static function sharedMarks(Score $score, array $barStarts, array $seconds, float $rangeStart,
    array $dynamicLevels): array {
    $marks = [];
    foreach ($score->scoreDynamics as $mark) {
      $marks[] = [
        'time' => (int)round((self::secondsAt($mark['time'], $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE),
        'end' => $mark['end'] === null ? null
          : (int)round((self::secondsAt($mark['end'], $barStarts, $seconds) - $rangeStart) * self::SAMPLE_RATE),
        'level' => $mark['level'], 'direction' => $mark['direction'],
        'gain' => $mark['level'] === '' ? null : $dynamicLevels[$mark['level']],
        'limit' => $dynamicLevels[$mark['direction'] === 'decrescendo' ? 'ppp' : 'fff'],
      ];
    }
    return $marks;
  }

  /** A shared mark replaces earlier local marks; a later local mark can take over. */
  private function eventGain(array $event, int $frame): float {
    $local = $event['volume'] * self::dynamicGain($event['dynamic'], $frame);
    if ($event['shared'] === []) {
      return $local;
    }
    if ($this->sharedCacheFrame !== $frame) {
      $this->sharedCacheFrame = $frame;
      $this->sharedGainCache = [];
    }
    $shift = $event['shift'];
    [$shared, $sharedTime] = $this->sharedGainCache[$shift]
      ??= self::sharedGain($event['shared'], $frame - $shift);
    return $shared !== null && $sharedTime + $event['shift'] >= $event['localTime'] ? $shared : $local;
  }

  /** Evaluate the shared level and any active wedge at one frame. */
  private static function sharedGain(array $marks, int $frame): array {
    $gain = null;
    $priority = PHP_INT_MIN;
    $ramp = null;
    $levels = [];
    foreach ($marks as $mark) {
      if ($mark['level'] !== '') {
        $levels[$mark['time']] = $mark['gain'];
      }
    }
    foreach ($marks as $mark) {
      if ($mark['time'] > $frame) {
        break;
      }
      if ($ramp !== null) {
        $progress = max(0.0, min(1.0, ($mark['time'] - $ramp['start']) / max(1, $ramp['end'] - $ramp['start'])));
        $gain = $ramp['from'] + ($ramp['to'] - $ramp['from']) * $progress;
        $priority = min($mark['time'], $ramp['end']);
        $ramp = null;
      }
      if ($mark['level'] !== '') {
        $gain = $mark['gain'];
        $priority = $mark['time'];
      }
      if ($mark['direction'] !== '') {
        $ramp = ['start' => $mark['time'], 'end' => $mark['end'], 'from' => $gain ?? 1.0,
          'to' => $levels[$mark['end']] ?? $mark['limit']];
        $priority = $mark['time'];
      }
    }
    if ($ramp !== null) {
      $progress = max(0.0, min(1.0, ($frame - $ramp['start']) / max(1, $ramp['end'] - $ramp['start'])));
      $gain = $ramp['from'] + ($ramp['to'] - $ramp['from']) * $progress;
      $priority = min($frame, $ramp['end']);
    }
    return [$gain, $priority];
  }

  /** Convert a half-beat position through each bar's BPM. */
  private static function secondsAt(float $position, array $barStarts, array $seconds): float {
    $last = count($barStarts) - 1;
    if ($position == $barStarts[$last]) {
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
