<?php

namespace MADSCORE\Play;

use MADSCORE\Score\DynamicLevel;
use MADSCORE\Score\Dynamics;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\FigurePlayback;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;
use MADSCORE\Score\Sound;

/** Write a standard MIDI file with one track per melody. */
final class MidiWriter {

  private const TICKS_PER_BEAT = 480;
  private const TICKS_PER_SLOT = 240;

  public static function write(string $path, Score $score, ?Project $project = null): void {
    $data = self::encode($score, project: $project);
    if (file_put_contents($path, $data) !== strlen($data)) {
      throw new \RuntimeException('Could not export MIDI: ' . $path);
    }
  }

  public static function encode(Score $score, ?int $selectedMelody = null, ?int $start = null, ?int $end = null,
    ?Project $project = null): string {
    $starts = [0];
    foreach ($score->bars as $bar) {
      $starts[] = end($starts) + $bar['beats'] * 2;
    }
    $wholeScore = $end === null;
    $start ??= 0;
    $end ??= end($starts);
    if ($start < 0 || $end > end($starts) || $start >= $end
      || ($selectedMelody !== null && !isset($score->melodies[$selectedMelody]))) {
      throw new \InvalidArgumentException('Invalid MIDI playback range or melody.');
    }
    $score = $score->visibleCopy();
    $timeline = new PlaybackTimeline($score, $start, $wholeScore ? null : $end);
    $passes = [];
    $outputStart = 0;
    foreach ($timeline->passes as [$sourceStart, $sourceEnd, $jump]) {
      $passes[] = [$sourceStart, $sourceEnd, $outputStart, $jump];
      $outputStart += $sourceEnd - $sourceStart;
    }
    $scoreEnd = $outputStart * self::TICKS_PER_SLOT;
    $conductor = [];
    $tracks = [];
    $melodyChannels = [];
    $dynamicControls = [];
    $freeChannels = [0, 1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 13, 14, 15];
    $glissandoEnds = [];
    foreach ($score->notes as $index => $note) {
      if (($note['glissandoToNext'] ?? false) && ($next = $score->nextAdjacent($index)) !== null) {
        $glissandoEnds[$next] = true;
      }
    }
    foreach ($score->melodies as $melody => $settings) {
      if (($selectedMelody !== null && $melody !== $selectedMelody) || $score->melodyMuted($melody)) {
        continue;
      }
      $tracks[$melody] = [];
      $instrument = $settings['instrument'] ?? Sound::legacyInstrument($settings['pattern']);
      $dynamicControls[$melody] = Sound::dynamicControl($instrument);
      if (($program = Sound::program($instrument)) !== null) {
        $channel = array_shift($freeChannels);
        if ($channel === null) {
          throw new \DomainException('MIDI supports at most 15 pitched melodies.');
        }
        $melodyChannels[$melody] = $channel;
        self::event($tracks[$melody], 0, 0, chr(0xc0 | $channel) . chr($program));
      } else if (Sound::drumKey($instrument) === null) {
        throw new \DomainException('Unknown MIDI instrument: ' . $instrument);
      }
    }
    $levels = self::noteLevels($score, $starts);
    foreach ($passes as $passIndex => [$sourceStart, $sourceEnd, $offset, $jump]) {
      foreach ($score->bars as $barIndex => $bar) {
        if ($starts[$barIndex + 1] <= $sourceStart || $starts[$barIndex] >= $sourceEnd) {
          continue;
        }
        $tick = ($offset + max(0, $starts[$barIndex] - $sourceStart)) * self::TICKS_PER_SLOT;
        $microseconds = (int)round(60000000 / (float)$bar['tempo']);
        if ($microseconds < 1 || $microseconds > 0xffffff) {
          throw new \DomainException('A bar tempo is outside the MIDI range.');
        }
        self::event($conductor, $tick, 0, "\xff\x51\x03" . substr(pack('N', $microseconds), 1));
        self::event($conductor, $tick, 1, "\xff\x58\x04" . chr($bar['beats']) . "\x02\x18\x08");
      }
      foreach ($score->scoreDynamics as $mark) {
        if ($mark['level'] === '' || $mark['time'] < $sourceStart || $mark['time'] >= $sourceEnd) {
          continue;
        }
        $tick = ($offset + $mark['time'] - $sourceStart) * self::TICKS_PER_SLOT;
        $value = max(0, min(127, (int)round(127 * DynamicLevel::percent($mark['level']) / 100)));
        foreach ($melodyChannels as $melody => $channel) {
          if ($dynamicControls[$melody] === 'expression') {
            self::event($tracks[$melody], $tick, -2, chr(0xb0 | $channel) . "\x0b" . chr($value));
          }
        }
      }
      foreach ($score->notes as $index => $note) {
        if (isset($glissandoEnds[$index])) {
          continue;
        }
        $start = $starts[$note['bar']] + $note['slot'];
        if (!isset($tracks[$note['melody']]) || $start >= $sourceEnd
          || ($wholeScore && $start < $sourceStart && $passIndex !== 0)) {
          continue;
        }
        $settings = $score->melodies[$note['melody']];
        $gain = $settings['volume'] / 100;
        if ($gain <= 0) {
          continue;
        }
        $track = &$tracks[$note['melody']];
        $onset = ($offset + $start - $sourceStart) * self::TICKS_PER_SLOT;
        $limit = ($offset + $sourceEnd - $sourceStart) * self::TICKS_PER_SLOT;
        $writtenEnd = $start + $note['length'];
        $instrument = $settings['instrument'] ?? Sound::legacyInstrument($settings['pattern']);
        if (($drumKey = Sound::drumKey($instrument)) !== null) {
          if ($onset < 0) {
            unset($track);
            continue;
          }
          $tempo = (float)$score->bars[$note['bar']]['tempo'];
          $duration = max(1, (int)round(Drum::duration($settings['pattern']) * $tempo / 60 * self::TICKS_PER_BEAT));
          self::note($track, 9, $drumKey, $onset,
            min($jump ? $limit : PHP_INT_MAX, $onset + $duration), $gain * $levels[$index]);
          unset($track);
          continue;
        }
        $channel = $melodyChannels[$note['melody']];
        $useExpression = $dynamicControls[$note['melody']] === 'expression';
        $noteGain = $useExpression ? $gain : $gain * $levels[$index];
        if (($parts = FigurePlayback::segments($note, $project)) !== null) {
          $pitch = 69 + ($note['octave'] - 4) * 12 + Pitch::position($note) - 1;
          foreach ($parts as $part) {
            $begin = $onset + (int)round($note['length'] * self::TICKS_PER_SLOT * $part['start']);
            $finish = $onset + (int)round($note['length'] * self::TICKS_PER_SLOT * $part['end']);
            self::note($track, $channel, $pitch + $part['offset'], $begin,
              min($finish, $jump ? $limit : PHP_INT_MAX), $noteGain);
          }
          unset($track);
          continue;
        }
        $next = ($note['glissandoToNext'] ?? false) ? $score->nextAdjacent($index) : null;
        if ($next !== null) {
          if ($useExpression) {
            $expression = max(0, min(127, (int)round(127 * $levels[$index])));
            self::event($track, max(0, $onset), $onset < 0 ? -3 : 1,
              chr(0xb0 | $channel) . "\x0b" . chr($expression));
          }
          $target = $score->notes[$next];
          $writtenEnd = $starts[$target['bar']] + $target['slot'] + $target['length'];
          $keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
          $from = 7 * $note['octave'] + array_search($note['pitch'], $keys, true);
          $to = 7 * $target['octave'] + array_search($target['pitch'], $keys, true);
          $count = abs($to - $from) + 1;
          for ($step = 0; $step < $count; $step++) {
            $key = $from + $step * ($to >= $from ? 1 : -1);
            $pitch = 69 + (intdiv($key, 7) - 4) * 12 + Pitch::naturalPosition($keys[$key % 7]) - 1;
            $begin = $onset + (int)round(($writtenEnd - $start) * self::TICKS_PER_SLOT * $step / $count);
            $end = $onset + (int)round(($writtenEnd - $start) * self::TICKS_PER_SLOT * ($step + 1) / $count);
            self::note($track, $channel, $pitch, $begin, min($end, $jump ? $limit : PHP_INT_MAX), $noteGain);
          }
          unset($track);
          continue;
        }
        if ($writtenEnd <= $sourceStart) {
          unset($track);
          continue;
        }
        if ($useExpression) {
          $expression = max(0, min(127, (int)round(127 * $levels[$index])));
          self::event($track, max(0, $onset), $onset < 0 ? -3 : 1,
            chr(0xb0 | $channel) . "\x0b" . chr($expression));
        }
        $end = $onset + ($writtenEnd - $start) * self::TICKS_PER_SLOT;
        $legatoSamePitch = false;
        if ($note['fermata'] ?? false) {
          $end = $onset + (int)round(($end - $onset) * 1.5);
        } else if (($note['articulation'] ?? '') === 'staccato') {
          $end = $onset + (int)round(($end - $onset) * 0.5);
        } else if (($note['slurToNext'] ?? false) && ($following = $score->nextAdjacent($index)) !== null) {
          $nextNote = $score->notes[$following];
          if ($note['octave'] !== $nextNote['octave']
            || Pitch::position($note) !== Pitch::position($nextNote)) {
            $end += 5;
          } else {
            $legatoSamePitch = self::samePitchLink($score, $index) !== null
              && $writtenEnd < $sourceEnd;
          }
        } else {
          $end -= min(14, (int)round(($end - $onset) * 0.2));
        }
        $trill = $note['trill'] ?? 0;
        $pulses = $trill === 0 ? 1 : 2 * $note['length'];
        for ($pulse = 0; $pulse < $pulses; $pulse++) {
          $begin = $onset + $pulse * 120;
          $finish = $pulse === $pulses - 1 ? $end : min($end, $begin + 120);
          $pitch = 69 + ($note['octave'] - 4) * 12 + Pitch::position($note) - 1
            + ($pulse % 2 ? $trill : 0);
          self::note($track, $channel, $pitch, $begin, min($finish, $jump ? $limit : PHP_INT_MAX), $noteGain);
        }
        if ($legatoSamePitch) {
          // Keep the previous voice under the next attack. Note-off precedes
          // note-on at their shared tick, so it cannot stop the new voice.
          self::event($track, $end, -1, chr(0xb0 | $channel) . "\x40\x7f");
          self::event($track, $end + 48, 3, chr(0xb0 | $channel) . "\x40\x00");
        }
        if (($note['articulation'] ?? '') === 'staccato') {
          // Piano SoundFonts can ring well after note-off. Damp the voice at
          // the written staccato end so the following gap is audible.
          self::event($track, min($end, $jump ? $limit : PHP_INT_MAX), 1,
            chr(0xb0 | $channel) . "\x78\x00");
        }
        unset($track);
      }
    }
    $chunks = [self::track($conductor, $scoreEnd)];
    foreach ($tracks as $events) {
      $chunks[] = self::track($events, $scoreEnd);
    }
    return 'MThd' . pack('Nnnn', 6, 1, count($chunks), self::TICKS_PER_BEAT) . implode('', $chunks);
  }

  /** Sample written dynamics at note onsets for velocity or expression. */
  private static function noteLevels(Score $score, array $starts): array {
    $result = [];
    $localTime = [];
    // MIDI soundfonts already give velocities a broad dynamic range; use the
    // written percentages instead of the software renderer's fitted levels.
    $written = [];
    foreach (DynamicLevel::marks() as $mark) {
      $written[$mark] = DynamicLevel::percent($mark) / 100;
    }
    $baseline = DynamicLevel::baseline($written);
    foreach ($score->melodies as $melody => $_) {
      $indices = array_keys(array_filter($score->notes, static fn (array $note): bool => $note['melody'] === $melody));
      usort($indices, static fn (int $a, int $b): int =>
        ($starts[$score->notes[$a]['bar']] + $score->notes[$a]['slot'])
        <=> ($starts[$score->notes[$b]['bar']] + $score->notes[$b]['slot']));
      $level = $baseline;
      $markedAt = PHP_INT_MIN;
      foreach ($indices as $index) {
        $note = $score->notes[$index];
        $time = $starts[$note['bar']] + $note['slot'];
        if (($mark = $note['dynamicLevel'] ?? '') !== '') {
          $level = $written[$mark] ?? DynamicLevel::percent($mark) / 100;
          $markedAt = $time;
        }
        $result[$index] = $level;
        $localTime[$index] = $markedAt;
      }
    }
    foreach (Dynamics::groups($score) as $group) {
      $from = $result[$group['first']];
      $lastMark = $score->notes[$group['last']]['dynamicLevel'] ?? '';
      $to = $lastMark !== '' ? ($written[$lastMark] ?? DynamicLevel::percent($lastMark) / 100)
        : $written[$group['direction'] === 'crescendo' ? 'fff' : 'ppp'];
      $endNote = $score->notes[$group['last']];
      $closing = $starts[$endNote['bar']] + $endNote['slot'];
      foreach ($group['indices'] as $index) {
        $note = $score->notes[$index];
        $time = $starts[$note['bar']] + $note['slot'];
        $fraction = ($time - $group['start']) / max(1, $closing - $group['start']);
        $result[$index] = $from + ($to - $from) * $fraction;
        $localTime[$index] = max($localTime[$index], $group['start']);
      }
      foreach ($score->notes as $index => $note) {
        $time = $starts[$note['bar']] + $note['slot'];
        if ($note['melody'] === $group['melody'] && $time > $closing && $localTime[$index] < $closing) {
          $result[$index] = $to;
          $localTime[$index] = $closing;
        }
      }
    }
    $shared = $score->scoreDynamics;
    usort($shared, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
    $sharedTargets = [];
    foreach ($shared as $mark) {
      if ($mark['level'] !== '') {
        $sharedTargets[$mark['time']] = $written[$mark['level']];
      }
    }
    foreach ($score->notes as $index => $note) {
      $time = $starts[$note['bar']] + $note['slot'];
      [$gain, $priority] = self::sharedLevelAt($shared, $sharedTargets, $written, $time, $baseline);
      if ($gain !== null && $priority >= $localTime[$index]) {
        $result[$index] = $gain;
      }
    }
    return $result;
  }

  /** Match score playback's persistent shared levels and wedges at one onset. */
  private static function sharedLevelAt(array $marks, array $targets, array $written, int $time,
    float $baseline): array {
    $gain = null;
    $priority = PHP_INT_MIN;
    $ramp = null;
    foreach ($marks as $mark) {
      if ($mark['time'] > $time) {
        break;
      }
      if ($ramp !== null) {
        $fraction = max(0.0, min(1.0,
          ($mark['time'] - $ramp['start']) / max(1, $ramp['end'] - $ramp['start'])));
        $gain = $ramp['from'] + ($ramp['to'] - $ramp['from']) * $fraction;
        $priority = min($mark['time'], $ramp['end']);
        $ramp = null;
      }
      if ($mark['level'] !== '') {
        $gain = $written[$mark['level']];
        $priority = $mark['time'];
      }
      if ($mark['direction'] !== '') {
        $ramp = ['start' => $mark['time'], 'end' => $mark['end'], 'from' => $gain ?? $baseline,
          'to' => $targets[$mark['end']] ?? $written[$mark['direction'] === 'crescendo' ? 'fff' : 'ppp']];
        $priority = $mark['time'];
      }
    }
    if ($ramp !== null) {
      $fraction = max(0.0, min(1.0, ($time - $ramp['start']) / max(1, $ramp['end'] - $ramp['start'])));
      $gain = $ramp['from'] + ($ramp['to'] - $ramp['from']) * $fraction;
      $priority = min($time, $ramp['end']);
    }
    return [$gain, $priority];
  }

  /** Check whether a repeated pitch can carry over under the next attack. */
  private static function samePitchLink(Score $score, int $index): ?int {
    $note = $score->notes[$index];
    if (!($note['slurToNext'] ?? false) || ($note['trill'] ?? 0) !== 0) {
      return null;
    }
    $following = $score->nextAdjacent($index);
    if ($following === null) {
      return null;
    }
    $next = $score->notes[$following];
    if ($note['octave'] !== $next['octave'] || Pitch::position($note) !== Pitch::position($next)
      || ($next['trill'] ?? 0) !== 0 || ($next['articulation'] ?? '') === 'staccato'
      || ($next['glissandoToNext'] ?? false)) {
      return null;
    }
    return $following;
  }

  private static function note(array &$events, int $channel, int $pitch, int $start, int $end, float $gain): void {
    if ($pitch < 0 || $pitch > 127) {
      throw new \DomainException('A note is outside MIDI pitch range 0–127.');
    }
    $start = max(0, $start);
    if ($start >= $end) {
      return;
    }
    $velocity = max(1, min(127, (int)round(100 * $gain)));
    self::event($events, $start, 2, chr(0x90 | $channel) . chr($pitch) . chr($velocity));
    self::event($events, $end, 0, chr(0x80 | $channel) . chr($pitch) . "\x00");
  }

  private static function event(array &$events, int $tick, int $order, string $data): void {
    $events[] = [$tick, $order, $data];
  }

  private static function track(array $events, int $end): string {
    usort($events, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $body = '';
    $position = 0;
    foreach ($events as [$tick, $_, $data]) {
      $body .= self::variable($tick - $position) . $data;
      $position = $tick;
    }
    $body .= self::variable(max(0, $end - $position)) . "\xff\x2f\x00";
    return 'MTrk' . pack('N', strlen($body)) . $body;
  }

  private static function variable(int $value): string {
    $bytes = chr($value & 0x7f);
    while (($value >>= 7) > 0) {
      $bytes = chr(($value & 0x7f) | 0x80) . $bytes;
    }
    return $bytes;
  }
}
