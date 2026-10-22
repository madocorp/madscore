<?php

namespace MADSCORE\Score;

/** Sample a phrase contour and a repeating local pattern in C major. */
final class PhraseGenerator {

  public const SHAPES = ['constant', 'linear', 'arch', 'valley', 'wave'];
  public const NAMES = ['wave', 'swelling-wave', 'double-wave', 'swelling-double',
    'quadratic-chirp', 'phase-warp', 'interference', 'wave-product'];
  private const C_MAJOR = [0, 2, 4, 5, 7, 9, 11];

  /** Unique local curves, including pitch inversion and reversal in time. */
  public static function patterns(): array {
    static $cached = null;
    if ($cached !== null) {
      return $cached;
    }
    $names = [];
    $seen = [];
    foreach (['', '-inverted', '-mirrored', '-mirrored-inverted'] as $variant) {
      foreach (self::NAMES as $base) {
        $name = $base . $variant;
        $signature = implode(',', array_map(static fn (int $i): string =>
          (string)(int)round(self::patternValue($name, $i / 64) * 1000000), range(0, 64)));
        if (!isset($seen[$signature])) {
          $seen[$signature] = true;
          $names[] = $name;
        }
      }
    }
    return $cached = $names;
  }

  public static function patternValue(string $name, float $u): float {
    $mirror = false;
    $invert = false;
    foreach (['-mirrored-inverted' => [true, true], '-inverted' => [false, true],
      '-mirrored' => [true, false]] as $suffix => $options) {
      if (str_ends_with($name, $suffix)) {
        $name = substr($name, 0, -strlen($suffix));
        [$mirror, $invert] = $options;
        break;
      }
    }
    if (!in_array($name, self::NAMES, true)) {
      throw new \DomainException('Unknown pitch shape.');
    }
    $value = self::value($name, $mirror ? 1 - $u : $u);
    return $invert ? -$value : $value;
  }

  public static function shapeValue(string $shape, float $progress): float {
    return match ($shape) {
      'constant' => 0.0,
      'linear' => 7 * $progress,
      'arch' => 6 * sin(M_PI * $progress),
      'valley' => -6 * sin(M_PI * $progress),
      'wave' => 3.5 * sin(2 * M_PI * $progress),
      default => throw new \DomainException('Unknown phrase shape.'),
    };
  }

  /** The local contribution in scale degrees, including the repeat boundary taper. */
  public static function localPatternValue(string $pattern, float $progress): float {
    return 2.25 * sin(M_PI * $progress) * self::patternValue($pattern, $progress);
  }

  /** Count changes of direction in the local curve, after its boundary taper. */
  private static function patternTurns(string $pattern): int {
    static $cached = [];
    if (isset($cached[$pattern])) {
      return $cached[$pattern];
    }
    $turns = 0;
    $direction = 0;
    $previous = self::localPatternValue($pattern, 0);
    for ($sample = 1; $sample <= 256; $sample++) {
      $value = self::localPatternValue($pattern, $sample / 256);
      $difference = $value - $previous;
      $next = $difference > 0.00001 ? 1 : ($difference < -0.00001 ? -1 : 0);
      if ($next !== 0) {
        if ($direction !== 0 && $next !== $direction) {
          $turns++;
        }
        $direction = $next;
      }
      $previous = $value;
    }
    return $cached[$pattern] = $turns;
  }

  /** Allow more turns as the chosen note palette grows. */
  public static function patternsForNoteCount(int $noteCount): array {
    $maximum = [3 => 2, 4 => 4, 5 => 6, 6 => 10, 7 => 13][$noteCount] ?? null;
    if ($maximum === null) {
      throw new \DomainException('Allowed notes must be from 3 to 7.');
    }
    return array_values(array_filter(self::patterns(),
      static fn (string $pattern): bool => self::patternTurns($pattern) <= $maximum));
  }

  public static function meter(int $beats, int $preferred = 4): int {
    if ($beats < 4 || $beats > 256) {
      throw new \DomainException('Composer beats must be from 4 to 256.');
    }
    if ($preferred >= 4 && $preferred <= 7 && $beats % $preferred === 0) {
      return $preferred;
    }
    foreach ([4, 5, 6, 7] as $meter) {
      if ($beats % $meter === 0) {
        return $meter;
      }
    }
    throw new \DomainException('Beats must divide into whole bars of 4, 5, 6, or 7.');
  }

  public static function choose(?string $except = null): string {
    $names = array_values(array_filter(self::NAMES, static fn (string $name): bool => $name !== $except));
    return $names[random_int(0, count($names) - 1)];
  }

  /** Choose a pitch-class subset and one of its notes as the phrase anchor. */
  public static function chooseNoteSet(int $count): array {
    if ($count < 3 || $count > 7) {
      throw new \DomainException('Distinct notes must be from 3 to 7.');
    }
    $degrees = range(0, 6);
    for ($index = 6; $index > 0; $index--) {
      $other = random_int(0, $index);
      [$degrees[$index], $degrees[$other]] = [$degrees[$other], $degrees[$index]];
    }
    $selected = array_slice($degrees, 0, $count);
    sort($selected);
    return ['noteSet' => $selected, 'startDegree' => $selected[random_int(0, $count - 1)]];
  }

  /** Stack scale thirds from a diatonic root; three notes make a triad. */
  public static function chordNoteSet(int $root, int $count): array {
    if ($root < 0 || $root > 6 || $count < 3 || $count > 5) {
      throw new \DomainException('Chord tones require 3 to 5 notes and a C major scale root.');
    }
    $selected = [];
    for ($index = 0; $index < $count; $index++) {
      $selected[] = ($root + 2 * $index) % 7;
    }
    sort($selected);
    return ['noteSet' => $selected, 'startDegree' => $root, 'chordRoot' => $root];
  }

  public static function chooseChordNoteSet(int $count): array {
    return self::chordNoteSet(random_int(0, 6), $count);
  }

  private static function validateNoteSet(?array $noteSet, ?int $startDegree): void {
    if ($noteSet === null && $startDegree === null) {
      return;
    }
    if (!is_array($noteSet) || !array_is_list($noteSet)
      || count($noteSet) < 3 || count($noteSet) > 7
      || !in_array($startDegree, $noteSet, true)) {
      throw new \DomainException('Invalid melody note set.');
    }
    foreach ($noteSet as $degree) {
      if (!is_int($degree) || $degree < 0 || $degree > 6) {
        throw new \DomainException('Invalid melody note set.');
      }
    }
    $sorted = $noteSet;
    sort($sorted, SORT_NUMERIC);
    if ($noteSet !== $sorted || $noteSet !== array_values(array_unique($noteSet))) {
      throw new \DomainException('Invalid melody note set.');
    }
  }

  /** Snap an absolute scale degree to a chosen note in any octave. */
  private static function nearestDegree(float $target, array $noteSet): int {
    $octave = (int)floor($target / 7);
    $best = 0;
    $distance = INF;
    for ($layer = $octave - 1; $layer <= $octave + 1; $layer++) {
      foreach ($noteSet as $degree) {
        $candidate = $layer * 7 + $degree;
        $delta = abs($candidate - $target);
        if ($delta < $distance || ($delta === $distance && $candidate < $best)) {
          $best = $candidate;
          $distance = $delta;
        }
      }
    }
    return $best;
  }

  /** Return both the sounding MIDI pitches and the unrounded curve for display. */
  public static function generate(int $beats, string $name): array {
    if (!in_array($name, self::NAMES, true)) {
      throw new \DomainException('Unknown pitch shape.');
    }
    $pitches = [];
    $degrees = [];
    for ($beat = 0; $beat < $beats; $beat++) {
      $degree = (int)round(3.5 * self::value($name, $beat / max(1, $beats - 1)));
      $degrees[] = $degree;
      $octave = (int)floor($degree / 7);
      $step = (($degree % 7) + 7) % 7;
      $pitches[] = 60 + 12 * $octave + self::C_MAJOR[$step];
    }
    $curve = [];
    $samples = max(120, $beats * 12);
    for ($sample = 0; $sample <= $samples; $sample++) {
      $curve[] = 3.5 * self::value($name, $sample / $samples);
    }
    return ['name' => $name, 'beats' => $beats, 'degrees' => $degrees,
      'pitches' => $pitches, 'curve' => $curve];
  }

  /** Choose two functions and a group size that divides the phrase into repeats. */
  public static function chooseLayers(int $beats, int $meter, ?array $except = null,
    int $noteCount = 7): array {
    $bars = intdiv($beats, $meter);
    $spans = array_values(array_filter(range(1, max(1, $bars - 1)),
      static fn (int $span): bool => $bars % $span === 0));
    $choices = [];
    foreach (self::SHAPES as $shape) {
      foreach (self::patternsForNoteCount($noteCount) as $pattern) {
        foreach ($spans as $repeatBars) {
          $choice = ['shape' => $shape, 'pattern' => $pattern, 'repeatBars' => $repeatBars];
          if ($choice !== $except) {
            $choices[] = $choice;
          }
        }
      }
    }
    return $choices[random_int(0, count($choices) - 1)];
  }

  public static function generateLayered(int $beats, int $meter, string $shape,
    string $pattern, int $repeatBars, bool $legacyVariation = false,
    ?array $noteSet = null, ?int $startDegree = null): array {
    self::validateLayers($beats, $meter, $shape, $pattern, $repeatBars);
    self::validateNoteSet($noteSet, $startDegree);
    $degrees = [];
    $pitches = [];
    for ($beat = 0; $beat < $beats; $beat++) {
      $target = ($startDegree ?? 0) + self::layeredValue($beat, $beats, $meter, $shape,
        $pattern, $repeatBars, $legacyVariation);
      $degree = $noteSet === null ? (int)round($target) : self::nearestDegree($target, $noteSet);
      $degrees[] = $degree;
      $octave = (int)floor($degree / 7);
      $step = (($degree % 7) + 7) % 7;
      $pitches[] = 60 + 12 * $octave + self::C_MAJOR[$step];
    }
    $curve = [];
    $samples = max(120, $beats * 12);
    for ($sample = 0; $sample <= $samples; $sample++) {
      $curve[] = ($startDegree ?? 0) + self::layeredValue($sample / $samples * ($beats - 1),
        $beats, $meter, $shape, $pattern, $repeatBars, $legacyVariation);
    }
    $phrase = ['name' => self::layeredName($shape, $pattern, $repeatBars),
      'shape' => $shape, 'pattern' => $pattern, 'repeatBars' => $repeatBars,
      'beats' => $beats, 'degrees' => $degrees, 'pitches' => $pitches, 'curve' => $curve];
    if ($noteSet !== null) {
      $phrase['noteSet'] = $noteSet;
      $phrase['startDegree'] = $startDegree;
    }
    return $phrase;
  }

  private static function validateLayers(int $beats, int $meter, string $shape,
    string $pattern, int $repeatBars): void {
    if (!in_array($shape, self::SHAPES, true) || !in_array($pattern, self::patterns(), true)
      || !in_array($meter, [4, 5, 6, 7], true) || $beats < 4 || $beats > 256
      || $beats % $meter !== 0 || $repeatBars < 1
      || $repeatBars > intdiv($beats, $meter)
      || intdiv($beats, $meter) % $repeatBars !== 0) {
      throw new \DomainException('Invalid layered phrase settings.');
    }
  }

  private static function layeredValue(float $beat, int $beats, int $meter,
    string $shape, string $pattern, int $repeatBars, bool $legacyVariation): float {
    $progress = $beat / ($beats - 1);
    $phrase = self::shapeValue($shape, $progress);
    $period = $meter * $repeatBars;
    $group = min(intdiv($beats, $period) - 1, (int)floor($beat / $period));
    $within = $beat - $group * $period;
    $local = min(1.0, $within / max(1, $period - 1));
    if ($legacyVariation) {
      $warped = $local + 0.12 * sin(($group + 1) * 1.9) * sin(M_PI * $local);
      $variation = 0.7 + 0.25 * sin(($group + 1) * 1.7);
      return $phrase + 2.25 * $variation * sin(M_PI * $local) * self::patternValue($pattern, $warped);
    }
    return $phrase + self::localPatternValue($pattern, $local);
  }

  private static function layeredName(string $shape, string $pattern, int $repeatBars): string {
    return $shape . ' + ' . $pattern . ' / ' . $repeatBars . ($repeatBars === 1 ? ' bar' : ' bars');
  }

  /** Keep the inputs needed to reconstruct the curve with its melody. */
  public static function metadata(int $beats, string $name, int $meter): array {
    return ['version' => 1, 'name' => $name, 'beats' => $beats,
      'meter' => $meter, 'scale' => 'C major', 'amplitude' => 3.5];
  }

  public static function layeredMetadata(int $beats, int $meter, string $shape,
    string $pattern, int $repeatBars, ?array $noteSet = null,
    ?int $startDegree = null, string $noteMode = 'random',
    ?int $chordRoot = null): array {
    self::validateLayers($beats, $meter, $shape, $pattern, $repeatBars);
    self::validateNoteSet($noteSet, $startDegree);
    if ($noteMode !== 'random' && $noteMode !== 'chord') {
      throw new \DomainException('Unknown note selection mode.');
    }
    if ($noteMode === 'chord') {
      if ($chordRoot === null || $noteSet === null
        || self::chordNoteSet($chordRoot, count($noteSet))['noteSet'] !== $noteSet
        || $startDegree !== $chordRoot) {
        throw new \DomainException('Invalid chord note set.');
      }
    } else if ($chordRoot !== null) {
      throw new \DomainException('Invalid chord note set.');
    }
    $metadata = ['version' => $noteSet === null ? 3 : 4,
      'beats' => $beats, 'meter' => $meter, 'scale' => 'C major',
      'shape' => $shape, 'pattern' => $pattern, 'repeatBars' => $repeatBars];
    if ($noteSet !== null) {
      $metadata['noteSet'] = $noteSet;
      $metadata['startDegree'] = $startDegree;
      if ($noteMode === 'chord') {
        $metadata['noteMode'] = 'chord';
        $metadata['chordRoot'] = $chordRoot;
      }
    }
    return $metadata;
  }

  public static function validateMetadata(mixed $metadata): array {
    if (!is_array($metadata)) {
      throw new \DomainException('Invalid melody generation settings.');
    }
    if (in_array($metadata['version'] ?? null, [2, 3, 4], true)) {
      if (($metadata['scale'] ?? null) !== 'C major'
        || !is_int($metadata['beats'] ?? null) || !is_int($metadata['meter'] ?? null)
        || !is_string($metadata['shape'] ?? null) || !is_string($metadata['pattern'] ?? null)
        || !is_int($metadata['repeatBars'] ?? null)) {
        throw new \DomainException('Invalid melody generation settings.');
      }
      try {
        self::validateLayers($metadata['beats'], $metadata['meter'], $metadata['shape'],
          $metadata['pattern'], $metadata['repeatBars']);
        if ($metadata['version'] === 4) {
          if (!is_array($metadata['noteSet'] ?? null)
            || !is_int($metadata['startDegree'] ?? null)) {
            throw new \DomainException('Invalid melody note set.');
          }
          self::validateNoteSet($metadata['noteSet'], $metadata['startDegree']);
          $noteMode = $metadata['noteMode'] ?? 'random';
          if ($noteMode !== 'random' && $noteMode !== 'chord') {
            throw new \DomainException('Unknown note selection mode.');
          }
          if ($noteMode === 'chord') {
            if (!is_int($metadata['chordRoot'] ?? null)
              || self::chordNoteSet($metadata['chordRoot'], count($metadata['noteSet']))['noteSet']
                !== $metadata['noteSet']
              || $metadata['startDegree'] !== $metadata['chordRoot']) {
              throw new \DomainException('Invalid chord note set.');
            }
          } else if (array_key_exists('chordRoot', $metadata)) {
            throw new \DomainException('Invalid chord note set.');
          }
        }
      } catch (\DomainException $error) {
        throw new \DomainException('Invalid melody generation settings.');
      }
      return $metadata;
    }
    if (($metadata['version'] ?? null) !== 1
      || !is_string($metadata['name'] ?? null)
      || !in_array($metadata['name'], self::NAMES, true)
      || !is_int($metadata['beats'] ?? null)
      || !is_int($metadata['meter'] ?? null)
      || !in_array($metadata['meter'], [4, 5, 6, 7], true)
      || ($metadata['scale'] ?? null) !== 'C major'
      || ($metadata['amplitude'] ?? null) !== 3.5
      || $metadata['beats'] < 4 || $metadata['beats'] > 256
      || $metadata['beats'] % $metadata['meter'] !== 0) {
      throw new \DomainException('Invalid melody generation settings.');
    }
    return $metadata;
  }

  public static function fromMetadata(array $metadata): array {
    self::validateMetadata($metadata);
    if (in_array($metadata['version'], [2, 3, 4], true)) {
      return self::generateLayered($metadata['beats'], $metadata['meter'], $metadata['shape'],
        $metadata['pattern'], $metadata['repeatBars'], $metadata['version'] === 2,
        $metadata['noteSet'] ?? null, $metadata['startDegree'] ?? null);
    }
    return self::generate($metadata['beats'], $metadata['name']);
  }

  public static function replaceLayered(Score $score, int $melody, int $beats, string $shape,
    string $pattern, int $repeatBars, ?int $preferredMeter = null,
    ?array $noteSet = null, ?int $startDegree = null,
    string $noteMode = 'random', ?int $chordRoot = null): array {
    $meter = self::meter($beats, $preferredMeter ?? $score->beats(0));
    $phrase = self::generateLayered($beats, $meter, $shape, $pattern, $repeatBars,
      noteSet: $noteSet, startDegree: $startDegree);
    $metadata = self::layeredMetadata($beats, $meter, $shape, $pattern,
      $repeatBars, $noteSet, $startDegree, $noteMode, $chordRoot);
    return self::replacePhrase($score, $melody, $beats, $meter, $phrase, $metadata);
  }

  /** Replace one melody atomically, keeping other melodies where their bars fit. */
  public static function replace(Score $score, int $melody, int $beats, string $name,
    ?int $preferredMeter = null): array {
    $meter = self::meter($beats, $preferredMeter ?? $score->beats(0));
    $phrase = self::generate($beats, $name);
    return self::replacePhrase($score, $melody, $beats, $meter, $phrase,
      self::metadata($beats, $name, $meter));
  }

  private static function replacePhrase(Score $score, int $melody, int $beats, int $meter,
    array $phrase, array $metadata): array {
    $score->assertMelodyEditable($melody);
    $bars = intdiv($beats, $meter);
    $next = clone $score;
    $next->notes = array_values(array_filter($next->notes,
      static fn (array $note): bool => $note['melody'] !== $melody));
    $otherContent = $next->notes !== [] || $next->scoreDynamics !== [];
    if (!$otherContent) {
      $first = $next->bars[0];
      $next->bars = [];
      for ($bar = 0; $bar < $bars; $bar++) {
        $next->bars[] = ['beats' => $meter, 'tempo' => $first['tempo'],
          'sectionLeader' => $bar === 0, 'repeats' => 0, 'note' => ''];
      }
    } else {
      for ($bar = 0; $bar < min($bars, count($next->bars)); $bar++) {
        if ($next->beats($bar) !== $meter) {
          throw new \DomainException('Other music uses different bar lengths; choose a beat count matching those bars.');
        }
      }
      while (count($next->bars) < $bars) {
        $next->ensureBar(count($next->bars));
        $next->bars[count($next->bars) - 1]['beats'] = $meter;
      }
    }
    $next->setBarNote(0, $phrase['name']);
    foreach ($phrase['pitches'] as $beat => $midi) {
      $position = $midi - 20;
      $next->place($melody, intdiv($beat, $meter), 2 * ($beat % $meter),
        intdiv($position - 1, 12), ($position - 1) % 12 + 1, 2);
    }
    $next->melodies[$melody]['generation'] = $metadata;
    return ['score' => $next, 'phrase' => $phrase, 'meter' => $meter];
  }

  private static function value(string $name, float $u): float {
    $x = 2 * M_PI * $u;
    return match ($name) {
      'wave' => sin($x),
      'swelling-wave' => $u * sin($x),
      'double-wave' => sin(2 * $x),
      'swelling-double' => (0.4 + 0.6 * $u) * sin(2 * $x),
      'quadratic-chirp' => sin($x * $x),
      'phase-warp' => sin(2 * $x + sin(3 * $x)),
      'interference' => (sin(2 * $x) + 0.5 * sin(5 * $x)) / 1.5,
      'wave-product' => sin(2 * $x) * sin(3 * $x),
    };
  }
}
