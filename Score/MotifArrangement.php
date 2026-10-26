<?php

namespace MADSCORE\Score;

/** Build a main score by placing named motif sections in pattern order. */
final class MotifArrangement {

  public static function build(Project $project, string $pattern): Score {
    $clean = preg_replace('/[^A-Z0-9| ]/', ' ', $pattern);
    $tokens = preg_split('/ +/', trim(str_replace('|', ' | ', $clean)), -1, PREG_SPLIT_NO_EMPTY);
    if ($tokens === [] || $tokens === false) {
      throw new \DomainException('Enter a motif pattern before building the score.');
    }
    $references = [];
    $sectionStart = true;
    $section = -1;
    foreach ($tokens as $token) {
      if ($token === '|') {
        if ($sectionStart) {
          throw new \DomainException('A section needs at least one motif.');
        }
        $sectionStart = true;
        continue;
      }
      if (preg_match('/^([A-Z]+)([0-9]*)$/D', $token, $parts) !== 1) {
        throw new \DomainException("Invalid motif reference: $token.");
      }
      $version = $parts[2] === '' ? 0 : filter_var($parts[2], FILTER_VALIDATE_INT);
      if ($version === false || $version < 0) {
        throw new \DomainException("Invalid motif version: $token.");
      }
      try {
        $project->item($parts[1]);
      } catch (\DomainException) {
        throw new \DomainException("Motif $parts[1] does not exist.");
      }
      $source = $project->score($parts[1]);
      $starts = [];
      foreach ($source->bars as $index => $bar) {
        if ($bar['sectionLeader']) {
          $starts[] = $index;
        }
      }
      if (!isset($starts[$version])) {
        throw new \DomainException("Motif $token does not exist.");
      }
      $start = $starts[$version];
      $music = $source->sectionMusic($start) ?? [];
      $end = $starts[$version + 1] ?? count($source->bars);
      $hasNotes = false;
      foreach ($source->notes as $note) {
        if ($note['bar'] >= $start && $note['bar'] < $end) {
          $hasNotes = true;
          break;
        }
      }
      if (!$hasNotes) {
        throw new \DomainException("Motif $token has no notes.");
      }
      if ($sectionStart) {
        $section++;
      }
      $references[] = [$source, $start, $end, $sectionStart, $section,
        $music];
      $sectionStart = false;
    }
    if ($sectionStart) {
      throw new \DomainException('A section needs at least one motif.');
    }

    $sectionMusic = [];
    foreach ($references as $reference) {
      $index = $reference[4];
      $music = $reference[5];
      if (!array_key_exists($index, $sectionMusic)) {
        $sectionMusic[$index] = $music;
      } else if ($sectionMusic[$index] !== $music) {
        $sectionMusic[$index] = [];
      }
    }

    $main = $project->score('main');
    $result = new Score();
    $result->title = $main->title;
    $result->author = $main->author;
    $result->catalog = $main->catalog;
    $result->bars = $result->notes = $result->scoreDynamics = [];
    $result->melodies = [];
    $normalDestinations = $chordDestinations = [];
    $nextChordId = 1;
    $activeMusic = null;
    foreach ($references as [$source, $start, $end, $newSection, $sectionIndex]) {
      $melodyMap = [];
      $normal = 0;
      foreach ($source->melodies as $index => $settings) {
        if (isset($settings['chordVoice'])) {
          $voice = $settings['chordVoice'];
          if (!isset($chordDestinations[$voice])) {
            $chordDestinations[$voice] = count($result->melodies);
            $result->melodies[] = $settings;
          }
          $melodyMap[$index] = $chordDestinations[$voice];
        } else {
          if (!isset($normalDestinations[$normal])) {
            $normalDestinations[$normal] = count($result->melodies);
            unset($settings['motif'], $settings['generation']);
            $result->melodies[] = $settings;
          }
          $melodyMap[$index] = $normalDestinations[$normal++];
        }
      }
      $barOffset = count($result->bars) - $start;
      $timeOffset = self::duration($result->bars) - $source->start($start);
      for ($bar = $start; $bar < $end; $bar++) {
        $settings = $source->bars[$bar];
        $sourceMusicChange = $settings['music'] ?? null;
        $hasMusicChange = array_key_exists('music', $settings);
        unset($settings['motifVersion'], $settings['music']);
        $settings['sectionLeader'] = $bar === $start && $newSection;
        $settings['repeats'] = 0;
        if ($settings['sectionLeader']) {
          $activeMusic = $sectionMusic[$sectionIndex] === []
            ? null : $sectionMusic[$sectionIndex];
          $settings['music'] = $activeMusic;
        } else if ($bar === $start) {
          $referenceMusic = $source->sectionMusic($start);
          if ($referenceMusic !== $activeMusic) {
            $settings['music'] = $referenceMusic;
            $activeMusic = $referenceMusic;
          }
        } else if ($hasMusicChange && $sourceMusicChange !== $activeMusic) {
          $settings['music'] = $sourceMusicChange;
          $activeMusic = $sourceMusicChange;
        }
        $result->bars[] = $settings;
      }
      $chordIds = [];
      foreach ($source->notes as $note) {
        if ($note['bar'] >= $start && $note['bar'] < $end) {
          $note['bar'] += $barOffset;
          $note['melody'] = $melodyMap[$note['melody']];
          if (isset($note['chord'])) {
            $oldId = $note['chord']['id'];
            $note['chord']['id'] = $chordIds[$oldId] ??= $nextChordId++;
          }
          $result->notes[] = $note;
        }
      }
      $startTime = $source->start($start);
      $endTime = $source->start($end);
      foreach ($source->scoreDynamics as $mark) {
        if ($mark['time'] >= $startTime && $mark['time'] < $endTime) {
          $mark['time'] += $timeOffset;
          if ($mark['end'] !== null) {
            $mark['end'] = min($mark['end'], $endTime) + $timeOffset;
          }
          $result->scoreDynamics[] = $mark;
        }
      }
    }
    return Score::fromData($result->data());
  }

  private static function duration(array $bars): int {
    return 2 * array_sum(array_column($bars, 'beats'));
  }
}
