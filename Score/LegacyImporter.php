<?php

namespace MADSCORE\Score;

/** Imports the web editor's text source into editable notes and bars. */
final class LegacyImporter {

  /** Return an imported score and a list of source features omitted from it. */
  public static function read(string $path): array {
    $source = file_get_contents($path);
    if ($source === false) {
      throw new \RuntimeException('Could not read legacy source.');
    }
    $lines = preg_split('/\r?\n/', $source);
    $settings = json_decode(array_shift($lines), true);
    if (!is_array($settings)) {
      throw new \DomainException('Legacy source must start with a JSON settings line.');
    }
    $score = new Score();
    foreach (['title', 'author', 'catalog'] as $field) {
      if (is_scalar($settings[$field] ?? null)) {
        $score->$field = (string)$settings[$field];
      }
    }
    $tempo = Score::legacyTempo(is_scalar($settings['tempo'] ?? null) ? (string)$settings['tempo'] : '');
    $warnings = [];
    $defaultOctaves = is_string($settings['octaves'] ?? null) ? $settings['octaves'] : 'x 0 -1! x';
    $octaves = self::octaveMap($defaultOctaves);
    if (array_key_exists('octaves', $settings)) {
      $warnings['custom octave display'] = true;
    }
    foreach (['margin', 'titleSize', 'infoSize', 'lyricsSize', 'noteSize', 'noteLine', 'lineWidth', 'noteSpacing', 'color1', 'color2', 'color3', 'color4', 'color5', 'color6'] as $field) {
      if (array_key_exists($field, $settings)) {
        $warnings['page styling and colors'] = true;
        break;
      }
    }
    $bars = [];
    $sectionStart = true;
    $barNote = '';
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if ($line === '---' || $line === '===' || $line === '###') {
        $sectionStart = true;
        if ($line === '###') {
          $warnings['page and row layout'] = true;
        }
        continue;
      }
      if ($line === '***') {
        $octaves = self::octaveMap($defaultOctaves);
        $warnings['page and row layout'] = true;
        continue;
      }
      if ($line === '...') {
        $warnings['page and row layout'] = true;
        continue;
      }
      if ($line[0] === '#') {
        $octaves = self::octaveMap(trim(substr($line, 1)));
        $warnings['custom octave display'] = true;
        continue;
      }
      if ($line[0] === ':' || $line[0] === '|') {
        $warnings['repeat or free-text marks'] = true;
        continue;
      }
      if (str_contains($line, '[')) {
        $warnings['lyrics'] = true;
      }
      preg_match_all('/\{([^}]*)\}/', $line, $groups);
      if ($groups[1] === []) {
        $barNote = self::appendBarNote($barNote, $line, $warnings);
        continue;
      }
      $bar = [];
      $longest = 0;
      foreach ($groups[1] as $melody => $group) {
        while (count($score->melodies) <= $melody) {
          $score->addMelody();
        }
        $position = 0;
        foreach (preg_split('/\s+/', trim($group)) as $token) {
          if ($token === '') {
            continue;
          }
          $parsed = self::note($token, $octaves);
          if ($parsed === null) {
            $warnings['unrecognized notes'] = true;
            continue;
          }
          if ($parsed['extra']) {
            $warnings['joins, dynamics, or note decorations'] = true;
          }
          if ($parsed['pitch'] !== 'X') {
            $bar[] = ['melody' => $melody, 'slot' => $position, 'octave' => $parsed['octave'],
              'pitch' => $parsed['pitch'], 'alter' => $parsed['alter'], 'length' => $parsed['length'],
              'continuesFromPrevious' => $parsed['continuesFromPrevious'],
              'continuesToNext' => $parsed['continuesToNext']];
          }
          $position += $parsed['length'];
        }
        $longest = max($longest, $position);
      }
      $barNote = self::appendBarNote($barNote,
        trim(preg_replace('/\{[^}]*\}|\[[^]]*\]/', '', $line)), $warnings);
      $bars[] = ['beats' => min(32, max(1, (int)ceil($longest / 2))),
        'sectionLeader' => $sectionStart, 'note' => $barNote, 'notes' => $bar];
      $sectionStart = false;
      $barNote = '';
    }
    if ($bars === []) {
      throw new \DomainException('Legacy source contains no editable bars.');
    }
    $score->bars = [];
    foreach ($bars as $index => $bar) {
      $score->bars[] = ['beats' => $bar['beats'], 'tempo' => $tempo,
        'sectionLeader' => $index === 0 || $bar['sectionLeader'], 'note' => $bar['note']];
    }
    foreach ($bars as $barNumber => $bar) {
      foreach ($bar['notes'] as $note) {
        $note['bar'] = $barNumber;
        $note['articulation'] = 'ordinary';
        $note['slurToNext'] = false;
        $note['fermata'] = false;
        $score->notes[] = $note;
      }
    }
    if (count($score->notes) === 0) {
      $warnings['no pitched notes found'] = true;
    }
    self::mergeContinuations($score, $warnings);
    self::splitWideMelodies($score, $warnings);
    $score = Score::fromData($score->data());
    return [$score, array_keys($warnings)];
  }

  /** Parse visual octave slots relative to the A3–G-sharp4 band. */
  private static function octaveMap(string $source): array {
    $slots = preg_split('/\s+/', trim($source));
    if (count($slots) < 4) {
      throw new \DomainException('Legacy octave layout needs four slots.');
    }
    $map = [];
    foreach (array_slice($slots, 0, 4) as $slot) {
      $slot = str_replace('!', '', $slot);
      $map[] = preg_match('/^-?\d+$/D', $slot) === 1 ? (int)$slot : null;
    }
    return $map;
  }

  /** Keep simple source labels with the next editable bar. */
  private static function appendBarNote(string $saved, string $next, array &$warnings): string {
    $next = trim(preg_replace('/\s+/', ' ', $next));
    if ($next === '') {
      return $saved;
    }
    $combined = $saved === '' ? $next : $saved . ' ' . $next;
    if (mb_strlen($combined) > 200) {
      $warnings['long bar annotations'] = true;
      return $saved;
    }
    return $combined;
  }

  /** Turn matched end/start marks into one note spanning the bar boundary. */
  private static function mergeContinuations(Score $score, array &$warnings): void {
    $starts = [0];
    foreach ($score->bars as $bar) {
      $starts[] = end($starts) + 2 * $bar['beats'];
    }
    $atBarStart = [];
    foreach ($score->notes as $index => $note) {
      if ($note['slot'] === 0) {
        $atBarStart[$note['bar'] . ':' . $note['melody']] = $index;
      }
    }
    $consumed = [];
    foreach ($score->notes as $index => $note) {
      if (isset($consumed[$index])) {
        continue;
      }
      $tail = $note;
      while ($tail['continuesToNext']) {
        $nextBar = $tail['bar'] + 1;
        $nextIndex = $atBarStart[$nextBar . ':' . $note['melody']] ?? null;
        $next = $nextIndex === null ? null : $score->notes[$nextIndex];
        if ($next === null || isset($consumed[$nextIndex]) || !$next['continuesFromPrevious']
          || $starts[$tail['bar']] + $tail['slot'] + $tail['length'] !== $starts[$nextBar]
          || $note['octave'] !== $next['octave']
          || Pitch::position($note) !== Pitch::position($next)
          || $score->notes[$index]['length'] + $next['length'] > 198) {
          $warnings['unmatched continuation marks'] = true;
          break;
        }
        $score->notes[$index]['length'] += $next['length'];
        $consumed[$nextIndex] = true;
        $tail = $next;
      }
      if ($note['continuesFromPrevious']) {
        $warnings['unmatched continuation marks'] = true;
      }
    }
    $notes = [];
    foreach ($score->notes as $index => $note) {
      if (!isset($consumed[$index])) {
        unset($note['continuesFromPrevious'], $note['continuesToNext']);
        $notes[] = $note;
      }
    }
    $score->notes = $notes;
  }

  /** Preserve pitch when an old melody crosses madscore's four-row limit. */
  private static function splitWideMelodies(Score $score, array &$warnings): void {
    for ($melody = 0; $melody < count($score->melodies); $melody++) {
      $pitches = [];
      foreach ($score->notes as $index => $note) {
        if ($note['melody'] === $melody) {
          $pitches[$index] = 12 * $note['octave'] + Pitch::position($note);
        }
      }
      while (MelodyRange::possibleBottoms(array_values($pitches)) === []) {
        $bestBottom = null;
        $bestCount = -1;
        for ($bottom = 0; $bottom <= 5; $bottom++) {
          $count = count(array_filter($pitches,
            static fn (int $pitch): bool => $pitch >= 12 * $bottom && $pitch <= 12 * $bottom + 48));
          if ($count > $bestCount) {
            $bestBottom = $bottom;
            $bestCount = $count;
          }
        }
        if ($bestCount === 0 || $bestCount === count($pitches)) {
          throw new \DomainException('Legacy melody has pitches outside the editable octave rows.');
        }
        $newMelody = $score->addMelody();
        foreach ($pitches as $index => $pitch) {
          if ($pitch < 12 * $bestBottom || $pitch > 12 * $bestBottom + 48) {
            $score->notes[$index]['melody'] = $newMelody;
            unset($pitches[$index]);
          }
        }
        $warnings['wide melodies split across rows'] = true;
      }
    }
  }

  /** Parse one old note token and its half-beat duration. */
  private static function note(string $token, array $octaves): ?array {
    if (!preg_match('/([a-gxA-GX])\1?/', $token, $match, PREG_OFFSET_CAPTURE)) {
      return null;
    }
    $letters = $match[0][0];
    $pitch = strtoupper($letters[0]);
    $oldBand = ctype_lower($letters[0]) ? (strlen($letters) === 2 ? 0 : 1) : (strlen($letters) === 2 ? 3 : 2);
    $length = str_contains($token, '/') ? 1 : 2;
    if (preg_match('/\d{1,2}/', $token, $size)) {
      $length *= (int)$size[0];
    }
    if ($length < 1 || $length > 198) {
      return null;
    }
    $alter = str_contains($token, "'") ? 1 : (str_contains($token, ',') ? -1 : 0);
    $remaining = preg_replace('/[a-gxA-GX]{1,2}|\d{1,2}|[\/,\x27>]/', '', $token);
    $octave = $octaves[$oldBand] === null ? null : 3 + $octaves[$oldBand];
    if ($pitch !== 'X' && $octave === null) {
      return null;
    }
    if ($pitch === 'A' && $alter === -1) {
      // A-flat is the G-sharp endpoint of the previous A-to-G-sharp band.
      $pitch = 'G';
      $alter = 1;
      $octave--;
    }
    return ['pitch' => $pitch, 'octave' => $octave ?? 0, 'alter' => $alter,
      'length' => $length, 'extra' => $remaining !== '',
      'continuesFromPrevious' => str_starts_with($token, '>'),
      'continuesToNext' => str_ends_with($token, '>')];
  }
}
