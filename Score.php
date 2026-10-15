<?php

namespace MADSCORE;

/** Stores bars and colored melodies in half-beat units. */
final class Score {

  public const DEFAULT_TEMPO = '120';
  public const MELODY_COUNT = 12;
  public const COLORS = ['#ff9000', '#0050ff', '#ff5050', '#604000', '#00bb00', '#bf00bf', '#008c8c', '#b05c00', '#7b55c7', '#cc3974', '#547800', '#5555aa'];

  public string $title = 'Untitled';
  public string $author = '';
  public string $catalog = '';
  public array $bars = [['beats' => 4, 'tempo' => self::DEFAULT_TEMPO, 'sectionLeader' => true]];
  public array $notes = [];
  public array $melodies;

  /** Start every melody with the current organ voice at full volume. */
  public function __construct() {
    $this->melodies = [];
    foreach (self::COLORS as $color) {
      $this->melodies[] = ['pattern' => 'organ', 'volume' => 100, 'color' => $color];
    }
  }

  /** Choose one of the available sound patterns for a melody. */
  public function setMelodyPattern(int $melody, string $pattern): void {
    if ($melody < 0 || $melody >= self::MELODY_COUNT || !in_array($pattern, Waveform::NAMES, true)) {
      throw new \DomainException('Invalid melody or sound pattern.');
    }
    $this->melodies[$melody]['pattern'] = $pattern;
  }

  /** Set a melody's volume as a percentage from mute to full. */
  public function setMelodyVolume(int $melody, int $volume): void {
    if ($melody < 0 || $melody >= self::MELODY_COUNT || $volume < 0 || $volume > 100) {
      throw new \DomainException('Melody volume must be between 0 and 100.');
    }
    $this->melodies[$melody]['volume'] = $volume;
  }

  /** Set the display color for one melody. */
  public function setMelodyColor(int $melody, string $color): void {
    if ($melody < 0 || $melody >= self::MELODY_COUNT || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
      throw new \DomainException('Melody color must be a six-digit hex color such as #ff9000.');
    }
    $this->melodies[$melody]['color'] = strtolower($color);
  }

  /** Return the half-beat position where a bar begins. */
  public function start(int $bar): int {
    $position = 0;
    for ($index = 0; $index < $bar; $index++) {
      $position += 2 * $this->beats($index);
    }
    return $position;
  }

  /** Return a bar's beat count, inheriting the last count for an empty preview tile. */
  public function beats(int $bar): int {
    return $this->bars[$bar]['beats'] ?? $this->bars[count($this->bars) - 1]['beats'];
  }

  /** Add bars until the requested index exists. */
  public function ensureBar(int $bar): void {
    while (count($this->bars) <= $bar) {
      $next = $this->bars[count($this->bars) - 1];
      $next['sectionLeader'] = false;
      $this->bars[] = $next;
    }
  }

  /** Discard an unused final bar after the cursor moves back out of it. */
  public function removeEmptyLastBar(int $bar): bool {
    $last = count($this->bars) - 1;
    if ($bar !== $last || $last === 0) {
      return false;
    }
    $start = $this->start($last);
    foreach ($this->notes as $note) {
      if ($this->start($note['bar']) + $note['slot'] + $note['length'] > $start) {
        return false;
      }
    }
    array_pop($this->bars);
    return true;
  }

  /** Mark a bar as the start of a section; the first bar always starts one. */
  public function setSectionLeader(int $bar, bool $leader): void {
    if (!isset($this->bars[$bar])) {
      throw new \DomainException('The bar is outside the score.');
    }
    if ($bar === 0 && !$leader) {
      throw new \DomainException('The first bar must start a section.');
    }
    $this->bars[$bar]['sectionLeader'] = $leader;
  }

  /** Return the one-based section containing a bar. */
  public function sectionNumber(int $bar): int {
    $number = 0;
    for ($index = 0; $index <= $bar; $index++) {
      $number += (int)$this->bars[$index]['sectionLeader'];
    }
    return $number;
  }

  /** Find a melody note sounding at the given cursor slot. */
  public function noteAt(int $melody, int $bar, int $slot): ?int {
    $position = $this->start($bar) + $slot;
    foreach ($this->notes as $index => $note) {
      if ($note['melody'] !== $melody) {
        continue;
      }
      $start = $this->start($note['bar']) + $note['slot'];
      if ($position >= $start && $position < $start + $note['length']) {
        return $index;
      }
    }
    return null;
  }

  /** Return positions occupied by this melody's notes in one bar. */
  public function notePositions(int $melody, int $bar): array {
    $barStart = $this->start($bar);
    $barEnd = $barStart + 2 * $this->beats($bar);
    $positions = [];
    foreach ($this->notes as $note) {
      if ($note['melody'] !== $melody) {
        continue;
      }
      $start = $this->start($note['bar']) + $note['slot'];
      if ($start >= $barEnd || $start + $note['length'] <= $barStart) {
        continue;
      }
      $positions[] = max(0, $start - $barStart);
    }
    sort($positions);
    return $positions;
  }

  /** Step between notes and bounded gaps, optionally stopping at the score's end. */
  public function moveCursor(int $melody, int $bar, int $slot, int $direction, bool $limitToScore = false): array {
    if ($direction !== -1 && $direction !== 1) {
      throw new \InvalidArgumentException('Cursor direction must be left or right.');
    }
    $position = $this->start($bar) + $slot;
    $currentIndex = $this->noteAt($melody, $bar, $slot);
    if ($currentIndex !== null) {
      $note = $this->notes[$currentIndex];
      $start = $this->start($note['bar']) + $note['slot'];
      $position = $direction === 1 ? $start + $note['length'] : $start - 1;
    } else if (($gap = $this->gapBounds($melody, $bar, $position)) !== null) {
      $position = $direction === 1 ? $gap[1] : $gap[0] - 1;
    } else if ($direction !== 1 || $slot !== 2 * $this->beats($bar)) {
      $position += $direction;
    }
    if ($limitToScore && $position >= $this->start(count($this->bars))) {
      $lastBar = count($this->bars) - 1;
      return [$lastBar, 2 * $this->beats($lastBar)];
    }
    [$nextBar, $nextSlot] = $this->cursorAt($position);
    $nextIndex = $this->noteAt($melody, $nextBar, $nextSlot);
    if ($nextIndex !== null) {
      $note = $this->notes[$nextIndex];
      return [$note['bar'], $note['slot']];
    }
    $gap = $this->gapBounds($melody, $nextBar, $position);
    if ($gap !== null) {
      return $this->cursorAt($gap[0]);
    }
    return [$nextBar, $nextSlot];
  }

  /** Use the current bar's portion of a bounded gap as the automatic width. */
  public function freeLength(int $melody, int $bar, int $slot, int $preferredLength): int {
    $position = $this->start($bar) + $slot;
    $gap = $this->gapBounds($melody, $bar, $position);
    return $gap === null ? $preferredLength : min(198, $gap[1] - $position);
  }

  /** Find a gap between notes, splitting it at bar boundaries when requested. */
  private function gapBounds(int $melody, int $bar, int $position, bool $withinBar = true): ?array {
    $barStart = $this->start($bar);
    $barEnd = $barStart + 2 * $this->beats($bar);
    if ($withinBar && $position >= $barEnd) {
      return null;
    }
    $previousEnd = 0;
    $nextStart = null;
    foreach ($this->notes as $note) {
      if ($note['melody'] !== $melody) {
        continue;
      }
      $start = $this->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      if ($start <= $position && $position < $end) {
        return null;
      }
      if ($end <= $position) {
        $previousEnd = max($previousEnd, $end);
      } else if ($start > $position) {
        $nextStart = $nextStart === null ? $start : min($nextStart, $start);
      }
    }
    if ($nextStart === null) {
      return null;
    }
    return $withinBar
      ? [max($previousEnd, $barStart), min($nextStart, $barEnd)]
      : [$previousEnd, $nextStart];
  }

  /** Resize or remove an empty cursor span, moving later notes in its melody. */
  public function resizeGapAndShift(int $melody, int $bar, int $slot, int $oldLength, int $length): void {
    if ($length < 0 || $length > 198 || $oldLength < 1 || $oldLength > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $position = $this->start($bar) + $slot;
    $gap = $this->gapBounds($melody, $bar, $position, false);
    if ($this->noteAt($melody, $bar, $slot) !== null || ($gap !== null && $oldLength > $gap[1] - $position)) {
      throw new \DomainException('The cursor span is not empty.');
    }
    $delta = $length - $oldLength;
    if ($delta === 0) {
      return;
    }
    $oldNotes = $this->notes;
    $oldBars = $this->bars;
    try {
      foreach ($oldNotes as $index => $note) {
        if ($note['melody'] !== $melody) {
          continue;
        }
        $start = $this->start($note['bar']) + $note['slot'];
        if ($start <= $position) {
          continue;
        }
        [$nextBar, $nextSlot] = $this->cursorAt($start + $delta);
        $this->notes[$index]['bar'] = $nextBar;
        $this->notes[$index]['slot'] = $nextSlot;
      }
      foreach ($this->notes as $index => $note) {
        $this->assertFree($note, $index);
        $this->ensureDuration($note);
      }
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
  }

  /** Place or replace a note at an onset without overlapping another note in its melody. */
  public function put(int $melody, int $bar, int $slot, int $octave, string $pitch, int $length = 2): int {
    if ($melody < 0 || $melody >= self::MELODY_COUNT || $length < 1 || $length > 198 || $octave < 0 || $octave > 8) {
      throw new \DomainException('Note length or octave is outside the score.');
    }
    $this->ensureBar($bar);
    $index = $this->noteAt($melody, $bar, $slot);
    if ($index !== null) {
      $note = $this->notes[$index];
      if ($note['bar'] !== $bar || $note['slot'] !== $slot) {
        throw new \DomainException('This position is inside an existing note.');
      }
      $replacement = $note;
      $replacement['octave'] = $octave;
      $replacement['pitch'] = $pitch;
      $replacement['alter'] = 0;
      $this->assertFree($replacement, $index);
      $this->notes[$index] = $replacement;
      return $index;
    }
    $note = ['melody' => $melody, 'bar' => $bar, 'slot' => $slot, 'octave' => $octave, 'pitch' => $pitch, 'alter' => 0, 'length' => $length];
    $this->assertFree($note, null);
    $this->notes[] = $note;
    $this->ensureDuration($note);
    return count($this->notes) - 1;
  }

  /** Place or replace a note with the cursor's exact pitch and width. */
  public function place(int $melody, int $bar, int $slot, int $octave, int $position, int $length): int {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    if ($melody < 0 || $melody >= self::MELODY_COUNT || $bar < 0 || $slot < 0 || $slot >= 2 * $this->beats($bar) || $octave < 0 || $octave > 8 || $position < 1 || $position > 12) {
      throw new \DomainException('The note position is outside the score.');
    }
    $this->ensureBar($bar);
    $index = $this->noteAt($melody, $bar, $slot);
    if ($index !== null && ($this->notes[$index]['bar'] !== $bar || $this->notes[$index]['slot'] !== $slot)) {
      throw new \DomainException('This position is inside an existing note.');
    }
    [$pitch, $alter] = Pitch::spelling($position);
    $note = ['melody' => $melody, 'bar' => $bar, 'slot' => $slot, 'octave' => $octave, 'pitch' => $pitch, 'alter' => $alter, 'length' => $length];
    $this->assertFree($note, $index);
    if ($index === null) {
      $this->notes[] = $note;
      $index = count($this->notes) - 1;
    } else {
      $this->notes[$index] = $note;
    }
    $this->ensureDuration($note);
    return $index;
  }

  /** Move a note by one semitone, crossing octave rows where needed. */
  public function stepPitch(int $index, int $direction): void {
    if ($direction !== -1 && $direction !== 1) {
      throw new \InvalidArgumentException('Pitch direction must be up or down.');
    }
    $note = $this->notes[$index];
    [$note['octave'], $position] = Pitch::step($note['octave'], Pitch::position($note), $direction);
    [$note['pitch'], $note['alter']] = Pitch::spelling($position);
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
  }

  /** Set a note's exact pitch without changing its onset or duration. */
  public function setPitchPosition(int $index, int $octave, int $position): void {
    if ($octave < 0 || $octave > 8 || $position < 1 || $position > 12) {
      throw new \DomainException('This pitch is outside the nine octave bands.');
    }
    [$pitch, $alter] = Pitch::spelling($position);
    $note = $this->notes[$index];
    $note['octave'] = $octave;
    $note['pitch'] = $pitch;
    $note['alter'] = $alter;
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
  }

  /** Resize a note and move later notes in its melody when needed. */
  public function resizeAndShift(int $index, int $length): array {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $note = $this->notes[$index];
    $start = $this->start($note['bar']) + $note['slot'];
    $delta = $length - $note['length'];
    if ($delta === 0) {
      return [];
    }
    $later = [];
    $nextStart = null;
    foreach ($this->notes as $otherIndex => $other) {
      if ($other['melody'] !== $note['melody'] || $otherIndex === $index) {
        continue;
      }
      $otherStart = $this->start($other['bar']) + $other['slot'];
      if ($otherStart > $start) {
        $later[$otherIndex] = $otherStart;
        $nextStart = $nextStart === null ? $otherStart : min($nextStart, $otherStart);
      }
    }
    $shift = $delta < 0 ? $delta : ($nextStart === null ? 0 : max(0, $start + $length - $nextStart));
    $oldNotes = $this->notes;
    $oldBars = $this->bars;
    try {
      $this->notes[$index]['length'] = $length;
      if ($shift !== 0) {
        foreach ($later as $otherIndex => $otherStart) {
          [$bar, $slot] = $this->cursorAt($otherStart + $shift);
          $this->notes[$otherIndex]['bar'] = $bar;
          $this->notes[$otherIndex]['slot'] = $slot;
        }
      }
      $changed = array_merge([$index], array_keys($shift === 0 ? [] : $later));
      foreach ($changed as $changedIndex) {
        $this->assertFree($this->notes[$changedIndex], $changedIndex);
        $this->ensureDuration($this->notes[$changedIndex]);
      }
      return $changed;
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
  }

  /** Add a one-beat note at this note's end with the same pitch and alteration. */
  public function baseAfter(int $index): int {
    return $this->copyAfter($index, 2);
  }

  /** Copy a note at its ending position with the same pitch, alteration, and duration. */
  public function repeatAfter(int $index): int {
    $note = $this->notes[$index];
    return $this->copyAfter($index, $note['length']);
  }

  /** Insert time in the source melody and place a copy in the new space. */
  private function copyAfter(int $index, int $length): int {
    $note = $this->notes[$index];
    $position = $this->start($note['bar']) + $note['slot'] + $note['length'];
    $oldNotes = $this->notes;
    $oldBars = $this->bars;
    try {
      foreach ($oldNotes as $otherIndex => $other) {
        if ($other['melody'] !== $note['melody']) {
          continue;
        }
        $start = $this->start($other['bar']) + $other['slot'];
        if ($start >= $position) {
          [$bar, $slot] = $this->cursorAt($start + $length);
          $this->notes[$otherIndex]['bar'] = $bar;
          $this->notes[$otherIndex]['slot'] = $slot;
        }
      }
      [$bar, $slot] = $this->cursorAt($position);
      $copy = $note;
      $copy['bar'] = $bar;
      $copy['slot'] = $slot;
      $copy['length'] = $length;
      foreach ($this->notes as $otherIndex => $other) {
        $this->assertFree($other, $otherIndex);
        $this->ensureDuration($other);
      }
      $this->assertFree($copy, null);
      $this->ensureDuration($copy);
      $this->notes[] = $copy;
      return count($this->notes) - 1;
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
  }

  /** Change a note's length while rejecting collisions. */
  public function setLength(int $index, int $length): void {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $note = $this->notes[$index];
    $note['length'] = $length;
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
    $this->ensureDuration($note);
  }

  /** Change one bar's beat count without placing an onset outside it or causing collisions. */
  public function setBeats(int $bar, int $beats): void {
    if ($beats < 1 || $beats > 32) {
      throw new \DomainException('Bar beats must be between 1 and 32.');
    }
    $previous = $this->bars[$bar]['beats'];
    $this->bars[$bar]['beats'] = $beats;
    try {
      foreach ($this->notes as $index => $note) {
        if ($note['bar'] === $bar && $note['slot'] >= 2 * $beats) {
          throw new \DomainException('A note starts beyond the new bar end.');
        }
        $this->assertFree($note, $index);
      }
    } catch (\Throwable $error) {
      $this->bars[$bar]['beats'] = $previous;
      throw $error;
    }
    foreach ($this->notes as $note) {
      $this->ensureDuration($note);
    }
  }

  /** Set one bar's positive tempo in beats per minute. */
  public function setTempo(int $bar, string $tempo): void {
    $tempo = trim($tempo);
    if (!self::validTempo($tempo)) {
      throw new \DomainException('Tempo must be a positive number of beats per minute.');
    }
    $this->bars[$bar]['tempo'] = $tempo;
  }

  /** Keep older beats-per-minute values, falling back when no number was given. */
  public static function legacyTempo(string $tempo): string {
    $tempo = trim($tempo);
    return self::validTempo($tempo) ? $tempo : self::DEFAULT_TEMPO;
  }

  private static function validTempo(string $tempo): bool {
    return preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $tempo) === 1
      && is_finite((float)$tempo) && (float)$tempo > 0;
  }

  /** Remove one note by its array index. */
  public function delete(int $index): void {
    array_splice($this->notes, $index, 1);
  }

  /** Locate an absolute half-beat position, extending the score if needed. */
  private function cursorAt(int $position): array {
    $position = max(0, $position);
    $bar = 0;
    $start = 0;
    while (true) {
      $end = $start + 2 * $this->beats($bar);
      if ($position < $end) {
        return [$bar, $position - $start];
      }
      $bar++;
      $start = $end;
      $this->ensureBar($bar);
    }
  }

  /** Validate that this note does not overlap the same melody. */
  private function assertFree(array $note, ?int $skip): void {
    $start = $this->start($note['bar']) + $note['slot'];
    $end = $start + $note['length'];
    foreach ($this->notes as $index => $other) {
      if ($index === $skip || $other['melody'] !== $note['melody']) {
        continue;
      }
      $otherStart = $this->start($other['bar']) + $other['slot'];
      if ($start < $otherStart + $other['length'] && $otherStart < $end) {
        throw new \DomainException('Notes in one melody cannot overlap.');
      }
    }
    for ($bar = $note['bar']; $this->start($bar) < $end; $bar++) {
      $barStart = $this->start($bar);
      $barEnd = $barStart + 2 * $this->beats($bar);
      $bands = [$note['octave'] => true];
      foreach ($this->notes as $index => $other) {
        if ($index === $skip) {
          continue;
        }
        $otherStart = $this->start($other['bar']) + $other['slot'];
        if ($otherStart < $barEnd && $otherStart + $other['length'] > $barStart) {
          $bands[$other['octave']] = true;
        }
      }
      if (count($bands) > 4) {
        throw new \DomainException('A bar can show notes in at most four octave bands.');
      }
    }
  }

  /** Add enough following bars to display a spanning note. */
  private function ensureDuration(array $note): void {
    $end = $this->start($note['bar']) + $note['slot'] + $note['length'];
    while ($end > $this->start(count($this->bars))) {
      $this->ensureBar(count($this->bars));
    }
  }

  /** Serialize the editable score. */
  public function data(): array {
    return ['version' => 6, 'title' => $this->title, 'author' => $this->author, 'catalog' => $this->catalog,
      'bars' => $this->bars, 'notes' => $this->notes, 'melodies' => $this->melodies];
  }

  /** Load and validate an application score document. */
  public static function fromData(array $data): self {
    $version = $data['version'] ?? null;
    if (!in_array($version, [1, 2, 3, 4, 5, 6], true) || !is_array($data['bars'] ?? null) || $data['bars'] === [] || !is_array($data['notes'] ?? null)) {
      throw new \DomainException('Unsupported or invalid Tristaff score.');
    }
    $score = new self();
    if ($version >= 5) {
      $melodies = $data['melodies'] ?? null;
      if (!is_array($melodies) || !array_is_list($melodies) || count($melodies) !== ($version === 5 ? 6 : self::MELODY_COUNT)) {
        throw new \DomainException('Invalid melody sound settings.');
      }
      foreach ($melodies as $index => $settings) {
        if (!is_array($settings) || !is_string($settings['pattern'] ?? null)
          || !is_int($settings['volume'] ?? null)) {
          throw new \DomainException('Invalid melody sound settings.');
        }
        $score->setMelodyPattern($index, $settings['pattern']);
        $score->setMelodyVolume($index, $settings['volume']);
        if ($version === 6) {
          if (!is_string($settings['color'] ?? null)) {
            throw new \DomainException('Invalid melody color.');
          }
          $score->setMelodyColor($index, $settings['color']);
        }
      }
    }
    foreach (['title', 'author', 'catalog'] as $field) {
      if (!is_string($data[$field] ?? null) || !mb_check_encoding($data[$field], 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $data[$field])) {
        throw new \DomainException('Invalid score metadata.');
      }
      $score->$field = $data[$field];
    }
    $legacyTempo = $data['tempo'] ?? '';
    if ($version < 3 && (!is_string($legacyTempo) || !mb_check_encoding($legacyTempo, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $legacyTempo))) {
      throw new \DomainException('Invalid score tempo.');
    }
    $score->bars = [];
    foreach ($data['bars'] as $index => $bar) {
      if (!is_array($bar) || !is_int($bar['beats'] ?? null) || $bar['beats'] < 1 || $bar['beats'] > 32) {
        throw new \DomainException('Invalid bar beat count.');
      }
      $tempo = $version < 3 ? $legacyTempo : ($bar['tempo'] ?? null);
      if (!is_string($tempo) || !mb_check_encoding($tempo, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $tempo)) {
        throw new \DomainException('Invalid bar tempo.');
      }
      $tempo = $version < 3 ? self::legacyTempo($tempo) : trim($tempo);
      if (!self::validTempo($tempo)) {
        throw new \DomainException('Invalid bar tempo.');
      }
      $leader = $version < 4 ? $index === 0 : ($bar['sectionLeader'] ?? null);
      if (!is_bool($leader) || ($index === 0 && !$leader)) {
        throw new \DomainException('Invalid section leader.');
      }
      $score->bars[] = ['beats' => $bar['beats'], 'tempo' => $tempo, 'sectionLeader' => $leader];
    }
    foreach ($data['notes'] as $note) {
      if ($version === 1) {
        if (!is_array($note) || !is_int($note['octave'] ?? null) || $note['octave'] < 0 || $note['octave'] > 3) {
          throw new \DomainException('Invalid legacy score note.');
        }
        $position = in_array($note['pitch'] ?? null, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true)
          && is_int($note['alter'] ?? null) ? Pitch::position($note) : -1;
        if ($position === 0) {
          $note['octave'] = 3 - $note['octave'];
          $note['pitch'] = 'G';
          $note['alter'] = 1;
        } else {
          $note['octave'] = 4 - $note['octave'];
        }
      }
      if (!self::validNote($note, $score)) {
        throw new \DomainException('Invalid score note.');
      }
      $score->assertFree($note, null);
      $score->notes[] = $note;
      $score->ensureDuration($note);
    }
    return $score;
  }

  /** Check the fields and bounds of a saved note. */
  private static function validNote(mixed $note, self $score): bool {
    if (!is_array($note) || !in_array($note['pitch'] ?? null, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true)) {
      return false;
    }
    foreach (['melody', 'bar', 'slot', 'octave', 'alter', 'length'] as $field) {
      if (!is_int($note[$field] ?? null)) {
        return false;
      }
    }
    return $note['melody'] >= 0 && $note['melody'] < self::MELODY_COUNT && $note['bar'] >= 0 && $note['bar'] < count($score->bars)
      && $note['slot'] >= 0 && $note['slot'] < 2 * $score->bars[$note['bar']]['beats']
      && $note['octave'] >= 0 && $note['octave'] <= 8 && abs($note['alter']) <= 1
      && Pitch::position($note) >= 1 && Pitch::position($note) <= 12
      && $note['length'] >= 1 && $note['length'] <= 198;
  }
}
