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
    if (array_key_exists('octaves', $settings)) {
      $warnings['custom octave layout'] = true;
    }
    foreach (['margin', 'titleSize', 'infoSize', 'lyricsSize', 'noteSize', 'noteLine', 'lineWidth', 'noteSpacing', 'color1', 'color2', 'color3', 'color4', 'color5', 'color6'] as $field) {
      if (array_key_exists($field, $settings)) {
        $warnings['page styling and colors'] = true;
        break;
      }
    }
    $bars = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (preg_match('/^(?:\*\*\*|\.\.\.|---|===|###)$/', $line)) {
        if ($line !== '---' && $line !== '===') {
          $warnings['page and row marks'] = true;
        }
        continue;
      }
      if ($line[0] === '#' || $line[0] === ':' || $line[0] === '|') {
        $warnings['octave, repeat, or free-text marks'] = true;
        continue;
      }
      if (str_contains($line, '[')) {
        $warnings['lyrics'] = true;
      }
      preg_match_all('/\{([^}]*)\}/', $line, $groups);
      if ($groups[1] === []) {
        $warnings['bar annotations'] = true;
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
          $parsed = self::note($token);
          if ($parsed === null) {
            $warnings['unrecognized notes'] = true;
            continue;
          }
          if ($parsed['extra']) {
            $warnings['joins, dynamics, or note decorations'] = true;
          }
          if (str_contains($token, '>')) {
            $warnings['legacy continuation marks'] = true;
          }
          if ($parsed['pitch'] !== 'X') {
            $bar[] = ['melody' => $melody, 'slot' => $position, 'octave' => $parsed['octave'], 'pitch' => $parsed['pitch'], 'alter' => $parsed['alter'], 'length' => $parsed['length']];
          }
          $position += $parsed['length'];
        }
        $longest = max($longest, $position);
      }
      $bars[] = ['beats' => min(32, max(1, (int)ceil($longest / 2))), 'notes' => $bar];
      if (trim(preg_replace('/\{[^}]*\}|\[[^]]*\]/', '', $line)) !== '') {
        $warnings['bar annotations'] = true;
      }
    }
    if ($bars === []) {
      throw new \DomainException('Legacy source contains no editable bars.');
    }
    $score->bars = [];
    foreach ($bars as $index => $bar) {
      $score->bars[] = ['beats' => $bar['beats'], 'tempo' => $tempo, 'sectionLeader' => $index === 0, 'note' => ''];
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
    $score = Score::fromData($score->data());
    return [$score, array_keys($warnings)];
  }

  /** Parse one old note token and its half-beat duration. */
  private static function note(string $token): ?array {
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
    $remaining = preg_replace('/[a-gxA-GX]{1,2}|\d{1,2}|[\/,\x27]/', '', $token);
    $octave = 1 + $oldBand;
    if ($pitch === 'A' && $alter === -1) {
      $pitch = 'G';
      $alter = 1;
      $octave--;
    }
    return ['pitch' => $pitch, 'octave' => $octave, 'alter' => $alter, 'length' => $length, 'extra' => $remaining !== ''];
  }
}
