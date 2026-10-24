<?php

namespace MADSCORE\Score;

use MADSCORE\Play\Drum;
use MADSCORE\Play\Waveform;

/** Stores bars and colored melodies in half-beat units. */
final class Score {

  public const DEFAULT_TEMPO = '120';
  // The first four are the print palette; later colors serve denser editor scores.
  public const COLORS = ['#c04b20', '#1264b0', '#16824b', '#8745a8', '#e60073', '#7300e6', '#998317', '#458b99', '#e600bf', '#994561', '#009900', '#004ce6'];

  public string $title = 'Untitled';
  public string $author = '';
  public string $catalog = '';
  public array $bars = [['beats' => 4, 'tempo' => self::DEFAULT_TEMPO, 'sectionLeader' => true, 'repeats' => 0, 'note' => '']];
  public array $notes = [];
  /** Shared level and wedge marks keyed by score time in half-beats. */
  public array $scoreDynamics = [];
  public array $melodies;
  /** Prefix times on read-only rendering copies; mutable editor scores leave this unset. */
  private ?array $renderStarts = null;

  /** Start every melody with acoustic grand piano at half volume. */
  public function __construct(int $melodyCount = 1) {
    if ($melodyCount < 1) {
      throw new \DomainException('A score needs at least one melody.');
    }
    $this->melodies = [];
    for ($index = 0; $index < $melodyCount; $index++) {
      $this->addMelody();
    }
  }

  /** Append an instrument and return its index. */
  public function addMelody(): int {
    $index = count($this->melodies);
    $this->melodies[] = ['instrument' => 'gm:0', 'pattern' => Sound::fallback('gm:0'), 'volume' => 50, 'muted' => false,
      'color' => self::COLORS[$index % count(self::COLORS)]];
    return $index;
  }

  /** Remove a melody and its notes, then renumber the melodies after it. */
  public function removeMelody(int $melody): void {
    if (!isset($this->melodies[$melody])) {
      throw new \DomainException('The melody does not exist.');
    }
    if (count($this->melodies) === 1) {
      throw new \DomainException('A score needs at least one melody.');
    }
    array_splice($this->melodies, $melody, 1);
    $notes = [];
    foreach ($this->notes as $note) {
      if ($note['melody'] === $melody) {
        continue;
      }
      if ($note['melody'] > $melody) {
        $note['melody']--;
      }
      $notes[] = $note;
    }
    $this->notes = $notes;
  }

  /** Choose one of the available sound patterns for a melody. */
  public function setMelodyPattern(int $melody, string $pattern): void {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || !in_array($pattern, Waveform::NAMES, true)) {
      throw new \DomainException('Invalid melody or sound pattern.');
    }
    $this->melodies[$melody]['pattern'] = $pattern;
    $this->melodies[$melody]['instrument'] = Sound::legacyInstrument($pattern);
    $this->normalizeSlurs();
  }

  /** Select a portable MIDI instrument and its approximate built-in sound. */
  public function setMelodyInstrument(int $melody, string $instrument): void {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || !Sound::valid($instrument)) {
      throw new \DomainException('Invalid melody or instrument.');
    }
    $this->melodies[$melody]['instrument'] = $instrument;
    $this->melodies[$melody]['pattern'] = Sound::fallback($instrument);
    $this->normalizeSlurs();
  }

  /** Set a melody's volume as a percentage from mute to full. */
  public function setMelodyVolume(int $melody, int $volume): void {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || $volume < 0 || $volume > 100) {
      throw new \DomainException('Melody volume must be between 0 and 100.');
    }
    $this->melodies[$melody]['volume'] = $volume;
  }

  /** Keep a melody in the document while excluding it from performance and print. */
  public function setMelodyMuted(int $melody, bool $muted): void {
    if (!isset($this->melodies[$melody])) {
      throw new \DomainException('The melody does not exist.');
    }
    $this->melodies[$melody]['muted'] = $muted;
  }

  public function melodyMuted(int $melody): bool {
    return $this->melodies[$melody]['muted'] ?? false;
  }

  /** Muted melodies may be selected, copied, unmuted, or deleted, but not changed. */
  public function assertMelodyEditable(int $melody): void {
    if (!isset($this->melodies[$melody])) {
      throw new \DomainException('The melody does not exist.');
    }
    if ($this->melodyMuted($melody)) {
      throw new \DomainException('This melody is muted and read-only. Unmute it to edit.');
    }
  }

  /** Whole-score edits must not move or alter any muted melody. */
  public function assertAllMelodiesEditable(): void {
    foreach (array_keys($this->melodies) as $melody) {
      $this->assertMelodyEditable($melody);
    }
  }

  /** A score copy without muted notes for drawing and playback. */
  public function visibleCopy(): self {
    if (!in_array(true, array_column($this->melodies, 'muted'), true)) {
      return $this;
    }
    $copy = clone $this;
    $copy->notes = array_values(array_filter($this->notes,
      fn (array $note): bool => !$this->melodyMuted($note['melody'])));
    return $copy;
  }

  /** Set the display color for one melody. */
  public function setMelodyColor(int $melody, string $color): void {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
      throw new \DomainException('Melody color must be a six-digit hex color such as #c04b20.');
    }
    $this->melodies[$melody]['color'] = strtolower($color);
  }

  /** Return the half-beat position where a bar begins. */
  public function start(int $bar): int {
    if ($this->renderStarts !== null) {
      $last = count($this->renderStarts) - 1;
      return $bar <= 0 ? 0 : ($this->renderStarts[$bar] ??
        $this->renderStarts[$last] + ($bar - $last) * 2 * $this->beats($last));
    }
    $position = 0;
    for ($index = 0; $index < $bar; $index++) {
      $position += 2 * $this->beats($index);
    }
    return $position;
  }

  /** Freeze bar start times on a copy used only for drawing. */
  public function renderCopy(): self {
    $copy = clone $this;
    $copy->renderStarts = [0];
    foreach ($copy->bars as $bar) {
      $copy->renderStarts[] = end($copy->renderStarts) + 2 * $bar['beats'];
    }
    return $copy;
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
      $next['repeats'] = 0;
      $next['note'] = '';
      unset($next['motifVersion']);
      unset($next['music']);
      $this->bars[] = $next;
    }
  }

  /** Discard an unused final bar after the cursor moves back out of it. */
  public function removeEmptyLastBar(int $bar): bool {
    $last = count($this->bars) - 1;
    if ($bar !== $last || $last === 0 || $this->bars[$last]['note'] !== '' || $this->bars[$last]['repeats'] > 0) {
      return false;
    }
    $start = $this->start($last);
    foreach ($this->scoreDynamics as $mark) {
      if ($mark['time'] >= $start || ($mark['end'] ?? 0) > $start) {
        return false;
      }
    }
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
    if (!$leader) {
      $this->bars[$bar]['repeats'] = 0;
      unset($this->bars[$bar]['motifVersion']);
      unset($this->bars[$bar]['music']);
    }
  }

  /** Return the first bar of the section containing a bar. */
  public function sectionStart(int $bar): int {
    if (!isset($this->bars[$bar])) {
      throw new \DomainException('The bar is outside the score.');
    }
    while (!$this->bars[$bar]['sectionLeader']) {
      $bar--;
    }
    return $bar;
  }

  /** Set the number of extra plays for the section containing a bar. */
  public function setSectionRepeats(int $bar, int $repeats): void {
    if ($repeats < 0 || $repeats > 8) {
      throw new \DomainException('Section repeats must be between 0 and 8.');
    }
    $this->bars[$this->sectionStart($bar)]['repeats'] = $repeats;
  }

  public function sectionRepeats(int $bar): int {
    return $this->bars[$this->sectionStart($bar)]['repeats'];
  }

  /** Set the optional text printed above this bar. */
  public function setBarNote(int $bar, string $note): void {
    if (!isset($this->bars[$bar])) {
      throw new \DomainException('The bar is outside the score.');
    }
    $note = trim($note);
    if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 200 || preg_match('/[\x00-\x1f\x7f]/', $note)) {
      throw new \DomainException('Bar note must be one printable line of at most 200 characters.');
    }
    $this->bars[$bar]['note'] = $note;
  }

  /** Return the one-based section containing a bar. */
  public function sectionNumber(int $bar): int {
    $number = 0;
    for ($index = 0; $index <= $bar; $index++) {
      $number += (int)$this->bars[$index]['sectionLeader'];
    }
    return $number;
  }

  public function sectionMusic(int $bar): ?array {
    return $this->bars[$this->sectionStart($bar)]['music'] ?? null;
  }

  public function setSectionMusic(int $bar, ?array $music): void {
    $start = $this->sectionStart($bar);
    if ($music === null) {
      unset($this->bars[$start]['music']);
    } else {
      $this->bars[$start]['music'] = HarmonicContext::validate($music);
    }
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

  /** Find the note beginning exactly where this one ends. */
  public function nextAdjacent(int $index): ?int {
    $note = $this->notes[$index];
    $end = $this->start($note['bar']) + $note['slot'] + $note['length'];
    foreach ($this->notes as $otherIndex => $other) {
      if ($otherIndex !== $index && $other['melody'] === $note['melody']
        && $this->start($other['bar']) + $other['slot'] === $end) {
        return $otherIndex;
      }
    }
    return null;
  }

  /** Discard links that no longer have a following note. */
  private function normalizeSlurs(): void {
    foreach ($this->notes as $index => $note) {
      if (($note['slurToNext'] ?? false) && $this->nextAdjacent($index) === null) {
        $this->notes[$index]['slurToNext'] = false;
      }
      if (($note['glissandoToNext'] ?? false) && !$this->validGlissando($index, true)) {
        $this->notes[$index]['glissandoToNext'] = false;
      }
      if (($note['dynamicToNext'] ?? '') !== '' && $this->nextMelodyNote($index) === null) {
        $this->notes[$index]['dynamicToNext'] = '';
      }
    }
  }

  /** Find the next written note in the same melody, allowing a gap. */
  public function nextMelodyNote(int $index): ?int {
    $note = $this->notes[$index];
    $start = $this->start($note['bar']) + $note['slot'];
    $next = null;
    $nextStart = PHP_INT_MAX;
    foreach ($this->notes as $otherIndex => $other) {
      if ($other['melody'] !== $note['melody'] || $otherIndex === $index) {
        continue;
      }
      $otherStart = $this->start($other['bar']) + $other['slot'];
      if ($otherStart > $start && $otherStart < $nextStart) {
        $next = $otherIndex;
        $nextStart = $otherStart;
      }
    }
    return $next;
  }

  /** Set a dynamic wedge from this note to the next note in its melody. */
  public function setDynamicToNext(int $index, string $direction): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if (!in_array($direction, ['', 'crescendo', 'decrescendo'], true)) {
      throw new \DomainException('Invalid dynamic direction.');
    }
    if ($direction !== '' && $this->nextMelodyNote($index) === null) {
      throw new \DomainException('A dynamic mark needs a next note in this melody.');
    }
    $this->notes[$index]['dynamicToNext'] = $direction;
  }

  /** Set a persistent volume level at this note, or remove its mark. */
  public function setDynamicLevel(int $index, string $level): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if ($level !== '' && !in_array($level, DynamicLevel::marks(), true)) {
      throw new \DomainException('Dynamic level must be ppp–fff or blank.');
    }
    $this->notes[$index]['dynamicLevel'] = $level;
  }

  /** Set or clear a level shared by every melody at this score position. */
  public function setScoreDynamicLevel(int $time, string $level): void {
    $this->assertScoreDynamicTime($time);
    if ($level !== '' && !in_array($level, DynamicLevel::marks(), true)) {
      throw new \DomainException('A shared level must be ppp–fff or blank.');
    }
    $this->changeScoreDynamic($time, 'level', $level);
  }

  /** Set or clear a score-wide wedge over an explicit time span. */
  public function setScoreDynamicWedge(int $start, int $end, string $direction): void {
    $this->assertScoreDynamicTime($start);
    if (!in_array($direction, ['', 'crescendo', 'decrescendo'], true)
      || ($direction !== '' && ($end <= $start || $end > $this->start(count($this->bars))))) {
      throw new \DomainException('A shared wedge needs a later score position.');
    }
    $this->changeScoreDynamic($start, 'direction', $direction, $direction === '' ? null : $end);
  }

  private function assertScoreDynamicTime(int $time): void {
    if ($time < 0 || $time >= $this->start(count($this->bars))) {
      throw new \DomainException('A shared dynamic mark must be inside the score.');
    }
  }

  private function changeScoreDynamic(int $time, string $field, string $value, ?int $end = null): void {
    foreach ($this->scoreDynamics as $index => $mark) {
      if ($mark['time'] !== $time) {
        continue;
      }
      $mark[$field] = $value;
      if ($field === 'direction') {
        $mark['end'] = $end;
      }
      if ($mark['level'] === '' && $mark['direction'] === '') {
        array_splice($this->scoreDynamics, $index, 1);
      } else {
        $this->scoreDynamics[$index] = $mark;
      }
      return;
    }
    if ($value !== '') {
      $this->scoreDynamics[] = ['time' => $time, 'level' => $field === 'level' ? $value : '',
        'direction' => $field === 'direction' ? $value : '', 'end' => $end];
      usort($this->scoreDynamics, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
    }
  }

  public function setStaccato(int $index, bool $staccato): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    $this->notes[$index]['articulation'] = $staccato ? 'staccato' : 'ordinary';
    if ($staccato) {
      unset($this->notes[$index]['figure']);
      $this->clearIncomingGlissando($index);
      $this->notes[$index]['slurToNext'] = false;
      $this->notes[$index]['glissandoToNext'] = false;
      $this->notes[$index]['fermata'] = false;
      $this->notes[$index]['trill'] = 0;
    }
  }

  public function setSlurToNext(int $index, bool $slur): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if ($slur && $this->nextAdjacent($index) === null) {
      throw new \DomainException('A slur needs an adjacent next note in this melody.');
    }
    $this->notes[$index]['slurToNext'] = $slur;
    if ($slur) {
      unset($this->notes[$index]['figure']);
      $this->clearIncomingGlissando($index);
      $this->notes[$index]['glissandoToNext'] = false;
      $this->notes[$index]['articulation'] = 'ordinary';
      $this->notes[$index]['fermata'] = false;
    }
  }

  /** Join two adjacent natural notes as one visible and sounding glide. */
  public function setGlissandoToNext(int $index, bool $glissando): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if ($glissando && !$this->validGlissando($index, true)) {
      throw new \DomainException('A glissando needs two adjacent white-key notes in the same melody.');
    }
    $this->notes[$index]['glissandoToNext'] = $glissando;
    if ($glissando) {
      $next = $this->nextAdjacent($index);
      unset($this->notes[$index]['figure'], $this->notes[$next]['figure']);
      $this->notes[$index]['slurToNext'] = false;
      $this->notes[$index]['articulation'] = 'ordinary';
      $this->notes[$index]['fermata'] = false;
      $this->notes[$index]['trill'] = 0;
      $this->notes[$next]['articulation'] = 'ordinary';
      $this->notes[$next]['slurToNext'] = false;
      $this->notes[$next]['fermata'] = false;
      $this->notes[$next]['trill'] = 0;
    }
  }

  private function validGlissando(int $index, bool $checkLinks = false): bool {
    $note = $this->notes[$index];
    $next = $this->nextAdjacent($index);
    if ($next === null || $note['alter'] !== 0 || $this->notes[$next]['alter'] !== 0
      || Pitch::position($note) === Pitch::position($this->notes[$next]) && $note['octave'] === $this->notes[$next]['octave']
      || in_array($this->melodies[$note['melody']]['pattern'], Drum::NAMES, true)) {
      return false;
    }
    if ($checkLinks) {
      foreach ($this->notes as $otherIndex => $other) {
        if ($otherIndex !== $index && ($other['glissandoToNext'] ?? false)
          && ($this->nextAdjacent($otherIndex) === $index || $otherIndex === $next)) {
          return false;
        }
      }
    }
    return true;
  }

  private function clearIncomingGlissando(int $index): void {
    foreach ($this->notes as $otherIndex => $other) {
      if (($other['glissandoToNext'] ?? false) && $this->nextAdjacent($otherIndex) === $index) {
        $this->notes[$otherIndex]['glissandoToNext'] = false;
      }
    }
  }

  public function setFermata(int $index, bool $fermata): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    $this->notes[$index]['fermata'] = $fermata;
    if ($fermata) {
      unset($this->notes[$index]['figure']);
      $this->clearIncomingGlissando($index);
      $this->notes[$index]['articulation'] = 'ordinary';
      $this->notes[$index]['slurToNext'] = false;
      $this->notes[$index]['glissandoToNext'] = false;
    }
  }

  /** Set the signed semitone distance to a trill's alternating pitch. */
  public function setTrill(int $index, int $interval): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if (!in_array($interval, [-2, -1, 0, 1, 2], true)
      || !self::validTrillPitch($this->notes[$index], $interval)) {
      throw new \DomainException('The trill pitch is outside the score.');
    }
    $this->notes[$index]['trill'] = $interval;
    if ($interval !== 0) {
      unset($this->notes[$index]['figure']);
      $this->clearIncomingGlissando($index);
      $this->notes[$index]['articulation'] = 'ordinary';
    }
  }

  /** Assign a left or right hand finger, or leave the note unmarked. */
  public function setFinger(int $index, string $finger): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    if ($finger !== '' && preg_match('/^[LR][1-5]$/D', $finger) !== 1) {
      throw new \DomainException('Finger must be L1–L5, R1–R5, or blank.');
    }
    $this->notes[$index]['finger'] = $finger;
  }

  /** Attach a compact triplet or a motif reference to one note. */
  public function setFigure(int $index, ?array $figure): void {
    if (!isset($this->notes[$index])) {
      throw new \OutOfBoundsException('No note at this index.');
    }
    $this->assertMelodyEditable($this->notes[$index]['melody']);
    $figure = Figure::validate($figure, $this->notes[$index]);
    if ($figure !== null && in_array($this->melodies[$this->notes[$index]['melody']]['pattern'], Drum::NAMES, true)) {
      throw new \DomainException('A figure needs a pitched melody.');
    }
    if ($figure === null) {
      unset($this->notes[$index]['figure']);
    } else {
      $this->clearIncomingGlissando($index);
      $this->notes[$index]['articulation'] = 'ordinary';
      $this->notes[$index]['slurToNext'] = false;
      $this->notes[$index]['glissandoToNext'] = false;
      $this->notes[$index]['fermata'] = false;
      $this->notes[$index]['trill'] = 0;
      $this->notes[$index]['figure'] = $figure;
    }
  }

  private static function validTrillPitch(array $note, int $interval): bool {
    $position = $note['octave'] * 12 + Pitch::position($note) + $interval;
    return $position >= 1 && $position <= 108;
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
    $this->assertMelodyEditable($melody);
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
      $this->normalizeSlurs();
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
  }

  /** Place or replace a note at an onset without overlapping another note in its melody. */
  public function put(int $melody, int $bar, int $slot, int $octave, string $pitch, int $length = 2): int {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || $length < 1 || $length > 198 || $octave < 0 || $octave > 8) {
      throw new \DomainException('Note length or octave is outside the score.');
    }
    if (!in_array($pitch, ['A', 'B', 'C', 'D', 'E', 'F', 'G'], true)) {
      throw new \DomainException('Invalid note pitch.');
    }
    $this->assertMelodyBand($melody, $octave, Pitch::naturalPosition($pitch), $this->noteAt($melody, $bar, $slot));
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
      $this->assertMelodyBand($melody, $octave, Pitch::naturalPosition($pitch), $index);
      $this->assertFree($replacement, $index);
      if (!self::validTrillPitch($replacement, $replacement['trill'] ?? 0)) {
        $replacement['trill'] = 0;
      }
      $this->notes[$index] = $replacement;
      $this->normalizeSlurs();
      return $index;
    }
    $note = ['melody' => $melody, 'bar' => $bar, 'slot' => $slot, 'octave' => $octave, 'pitch' => $pitch, 'alter' => 0, 'length' => $length,
      'articulation' => 'ordinary', 'slurToNext' => false, 'glissandoToNext' => false, 'fermata' => false, 'trill' => 0, 'finger' => '', 'dynamicToNext' => '', 'dynamicLevel' => ''];
    $this->assertMelodyBand($melody, $octave, Pitch::naturalPosition($pitch));
    $this->assertFree($note, null);
    $this->notes[] = $note;
    $this->ensureDuration($note);
    $this->normalizeSlurs();
    return count($this->notes) - 1;
  }

  /** Place or replace a note with the cursor's exact pitch and width. */
  public function place(int $melody, int $bar, int $slot, int $octave, int $position, int $length): int {
    $this->assertMelodyEditable($melody);
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    if (!isset($this->melodies[$melody]) || $bar < 0 || $slot < 0 || $slot >= 2 * $this->beats($bar) || $octave < 0 || $octave > 8 || $position < 1 || $position > 12) {
      throw new \DomainException('The note position is outside the score.');
    }
    $this->ensureBar($bar);
    $index = $this->noteAt($melody, $bar, $slot);
    if ($index !== null && ($this->notes[$index]['bar'] !== $bar || $this->notes[$index]['slot'] !== $slot)) {
      throw new \DomainException('This position is inside an existing note.');
    }
    [$pitch, $alter] = Pitch::spelling($position);
    $note = $index === null
      ? ['melody' => $melody, 'bar' => $bar, 'slot' => $slot, 'octave' => $octave, 'pitch' => $pitch, 'alter' => $alter, 'length' => $length,
        'articulation' => 'ordinary', 'slurToNext' => false, 'glissandoToNext' => false, 'fermata' => false, 'trill' => 0, 'finger' => '', 'dynamicToNext' => '', 'dynamicLevel' => '']
      : array_replace($this->notes[$index], ['octave' => $octave, 'pitch' => $pitch, 'alter' => $alter, 'length' => $length]);
    if (!self::validTrillPitch($note, $note['trill'] ?? 0)) {
      $note['trill'] = 0;
    }
    if (isset($note['figure'])) {
      Figure::validate($note['figure'], $note);
    }
    $this->assertMelodyBand($melody, $octave, $position, $index);
    $this->assertFree($note, $index);
    if ($index === null) {
      $this->notes[] = $note;
      $index = count($this->notes) - 1;
    } else {
      $this->notes[$index] = $note;
    }
    $this->ensureDuration($note);
    $this->normalizeSlurs();
    return $index;
  }

  /** Move a note by one semitone, crossing octave rows where needed. */
  public function stepPitch(int $index, int $direction): void {
    if ($direction !== -1 && $direction !== 1) {
      throw new \InvalidArgumentException('Pitch direction must be up or down.');
    }
    $note = $this->notes[$index];
    $this->assertMelodyEditable($note['melody']);
    [$note['octave'], $position] = Pitch::step($note['octave'], Pitch::position($note), $direction);
    $this->assertMelodyBand($note['melody'], $note['octave'], $position, $index);
    [$note['pitch'], $note['alter']] = Pitch::spelling($position);
    if (!self::validTrillPitch($note, $note['trill'] ?? 0)) {
      $note['trill'] = 0;
    }
    if (isset($note['figure'])) {
      Figure::validate($note['figure'], $note);
    }
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
    $this->normalizeSlurs();
  }

  /** Set a note's exact pitch without changing its onset or duration. */
  public function setPitchPosition(int $index, int $octave, int $position): void {
    if ($octave < 0 || $octave > 8 || $position < 1 || $position > 12) {
      throw new \DomainException('This pitch is outside the nine octave bands.');
    }
    [$pitch, $alter] = Pitch::spelling($position);
    $note = $this->notes[$index];
    $this->assertMelodyEditable($note['melody']);
    $note['octave'] = $octave;
    $this->assertMelodyBand($note['melody'], $octave, $position, $index);
    $note['pitch'] = $pitch;
    $note['alter'] = $alter;
    if (!self::validTrillPitch($note, $note['trill'] ?? 0)) {
      $note['trill'] = 0;
    }
    if (isset($note['figure'])) {
      Figure::validate($note['figure'], $note);
    }
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
    $this->normalizeSlurs();
  }

  /** Resize a note and move later notes in its melody when needed. */
  public function resizeAndShift(int $index, int $length): array {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $note = $this->notes[$index];
    $this->assertMelodyEditable($note['melody']);
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
      $this->normalizeSlurs();
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

  /** Insert a note at the left edge unless it separates adjacent notes. */
  public function insertInverseBeat(int $melody, int $bar, int $slot, int $octave, int $pitchPosition): array {
    $this->assertMelodyEditable($melody);
    if (!isset($this->melodies[$melody]) || !isset($this->bars[$bar]) || $slot < 0 || $slot > 2 * $this->beats($bar)
      || $octave < 0 || $octave > 8 || $pitchPosition < 1 || $pitchPosition > 12) {
      throw new \DomainException('The insertion position is outside the score.');
    }
    $current = $this->noteAt($melody, $bar, $slot);
    $position = $current === null ? $this->start($bar) + $slot
      : $this->start($this->notes[$current]['bar']) + $this->notes[$current]['slot'];
    $oldNotes = $this->notes;
    $oldBars = $this->bars;
    try {
      [$bar, $slot] = $this->cursorAt($position);
      $at = $this->noteAt($melody, $bar, $slot);
      $leftIsNote = false;
      foreach ($oldNotes as $otherIndex => $other) {
        if ($other['melody'] !== $melody) {
          continue;
        }
        $start = $this->start($other['bar']) + $other['slot'];
        if ($start < $position && $start + $other['length'] >= $position) {
          $leftIsNote = true;
        }
        if ($start >= $position) {
          [$nextBar, $nextSlot] = $this->cursorAt($start + 2);
          $this->notes[$otherIndex]['bar'] = $nextBar;
          $this->notes[$otherIndex]['slot'] = $nextSlot;
        }
      }
      [$bar, $slot] = $this->cursorAt($position);
      $inserted = $at === null || !$leftIsNote
        ? $this->place($melody, $bar, $slot, $octave, $pitchPosition, 2) : null;
      foreach ($this->notes as $otherIndex => $other) {
        $this->assertFree($other, $otherIndex);
        $this->ensureDuration($other);
      }
      $this->normalizeSlurs();
      return [$bar, $slot, $inserted];
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
  }

  /** Insert time in the source melody and place a copy in the new space. */
  private function copyAfter(int $index, int $length): int {
    $note = $this->notes[$index];
    $this->assertMelodyEditable($note['melody']);
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
      $copy['slurToNext'] = false;
      $copy['glissandoToNext'] = false;
      $copy['dynamicToNext'] = '';
      $copy['dynamicLevel'] = '';
      foreach ($this->notes as $otherIndex => $other) {
        $this->assertFree($other, $otherIndex);
        $this->ensureDuration($other);
      }
      $this->assertFree($copy, null);
      $this->ensureDuration($copy);
      $this->notes[] = $copy;
      $this->normalizeSlurs();
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
    $this->assertMelodyEditable($note['melody']);
    $note['length'] = $length;
    $this->assertFree($note, $index);
    $this->notes[$index] = $note;
    $this->ensureDuration($note);
    $this->normalizeSlurs();
  }

  /** Change one bar's beat count, carrying displaced notes into following bars. */
  public function setBeats(int $bar, int $beats): void {
    if ($beats < 1 || $beats > 32) {
      throw new \DomainException('Bar beats must be between 1 and 32.');
    }
    $previous = $this->bars[$bar]['beats'];
    if ($beats !== $previous) {
      $this->assertAllMelodiesEditable();
    }
    $oldBars = $this->bars;
    $oldNotes = $this->notes;
    $oldDynamics = $this->scoreDynamics;
    $oldEnd = $this->start($bar + 1);
    $positions = [];
    if ($beats < $previous) {
      foreach ($oldNotes as $index => $note) {
        if ($note['bar'] > $bar || ($note['bar'] === $bar && $note['slot'] >= 2 * $beats)) {
          $positions[$index] = $this->start($note['bar']) + $note['slot'];
        }
      }
    }
    $this->bars[$bar]['beats'] = $beats;
    try {
      $delta = 2 * ($beats - $previous);
      foreach ($this->scoreDynamics as &$mark) {
        if ($mark['time'] >= $oldEnd) {
          $mark['time'] += $delta;
        }
        if ($mark['end'] !== null && $mark['end'] >= $oldEnd) {
          $mark['end'] += $delta;
        }
      }
      unset($mark);
      foreach ($this->scoreDynamics as $mark) {
        while (($mark['end'] ?? $mark['time'] + 1) > $this->start(count($this->bars))) {
          $this->ensureBar(count($this->bars));
        }
      }
      usort($this->scoreDynamics, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);
      $previousMark = -1;
      foreach ($this->scoreDynamics as $mark) {
        if ($mark['time'] <= $previousMark || ($mark['end'] !== null && $mark['end'] <= $mark['time'])) {
          throw new \DomainException('Changing beats would overlap shared dynamic marks.');
        }
        $previousMark = $mark['time'];
      }
      foreach ($positions as $index => $position) {
        [$this->notes[$index]['bar'], $this->notes[$index]['slot']] = $this->cursorAt($position);
      }
      foreach ($this->notes as $index => $note) {
        $this->assertFree($note, $index);
        $this->ensureDuration($note);
      }
      $this->normalizeSlurs();
    } catch (\Throwable $error) {
      $this->bars = $oldBars;
      $this->notes = $oldNotes;
      $this->scoreDynamics = $oldDynamics;
      throw $error;
    }
  }

  /** Change a bar's tempo and carry it through the following unchanged bars. */
  public function setTempo(int $bar, string $tempo): void {
    $tempo = trim($tempo);
    if (!self::validTempo($tempo)) {
      throw new \DomainException('Tempo must be a positive number of beats per minute.');
    }
    if (!isset($this->bars[$bar])) {
      throw new \DomainException('The bar is outside the score.');
    }
    $previous = $this->bars[$bar]['tempo'];
    $this->bars[$bar]['tempo'] = $tempo;
    if ((float)$tempo === (float)$previous) {
      return;
    }
    for ($index = $bar + 1; $index < count($this->bars); $index++) {
      if ((float)$this->bars[$index]['tempo'] !== (float)$previous) {
        break;
      }
      $this->bars[$index]['tempo'] = $tempo;
    }
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
    $note = $this->notes[$index] ?? throw new \OutOfBoundsException('No note at this index.');
    $this->assertMelodyEditable($note['melody']);
    array_splice($this->notes, $index, 1);
    $this->normalizeSlurs();
  }

  /** Remove a note and the following gap, bringing the next note to its onset. */
  public function deleteAndClose(int $index): void {
    $note = $this->notes[$index] ?? throw new \OutOfBoundsException('No note at this index.');
    $this->assertMelodyEditable($note['melody']);
    $start = $this->start($note['bar']) + $note['slot'];
    $nextStart = null;
    foreach ($this->notes as $otherIndex => $other) {
      if ($otherIndex === $index || $other['melody'] !== $note['melody']) {
        continue;
      }
      $otherStart = $this->start($other['bar']) + $other['slot'];
      if ($otherStart > $start) {
        $nextStart = $nextStart === null ? $otherStart : min($nextStart, $otherStart);
      }
    }
    $oldNotes = $this->notes;
    $oldBars = $this->bars;
    try {
      array_splice($this->notes, $index, 1);
      if ($nextStart !== null) {
        $removedLength = $nextStart - $start;
        foreach ($this->notes as $otherIndex => $other) {
          if ($other['melody'] !== $note['melody']) {
            continue;
          }
          $otherStart = $this->start($other['bar']) + $other['slot'];
          if ($otherStart >= $nextStart) {
            [$bar, $slot] = $this->cursorAt($otherStart - $removedLength);
            $this->notes[$otherIndex]['bar'] = $bar;
            $this->notes[$otherIndex]['slot'] = $slot;
          }
        }
        foreach ($this->notes as $otherIndex => $other) {
          $this->assertFree($other, $otherIndex);
          $this->ensureDuration($other);
        }
      }
      $this->normalizeSlurs();
    } catch (\Throwable $error) {
      $this->notes = $oldNotes;
      $this->bars = $oldBars;
      throw $error;
    }
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
  }

  private function assertMelodyBand(int $melody, int $octave, int $position, ?int $skip = null): void {
    $pitches = [12 * $octave + $position];
    foreach ($this->notes as $index => $note) {
      if ($index !== $skip && $note['melody'] === $melody) {
        $pitches[] = 12 * $note['octave'] + Pitch::position($note);
      }
    }
    if (MelodyRange::possibleBottoms($pitches) === []) {
      throw new \DomainException('A melody must fit four octave rows from G-sharp to G-sharp.');
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
    return ['title' => $this->title, 'author' => $this->author, 'catalog' => $this->catalog,
      'bars' => $this->bars, 'notes' => $this->notes, 'melodies' => $this->melodies,
      'scoreDynamics' => $this->scoreDynamics];
  }

  /** Load and validate an application score document. */
  public static function fromData(array $data): self {
    if (!is_array($data['bars'] ?? null) || $data['bars'] === [] || !is_array($data['notes'] ?? null)
      || !is_array($data['melodies'] ?? null) || !array_is_list($data['melodies']) || $data['melodies'] === []) {
      throw new \DomainException('Invalid Tristaff score.');
    }
    $score = new self();
    $score->melodies = [];
    $motifLabels = [];
    $chordVoices = [];
    foreach ($data['melodies'] as $index => $settings) {
      if (!is_array($settings) || !is_string($settings['pattern'] ?? null)
        || !is_int($settings['volume'] ?? null) || !is_string($settings['color'] ?? null)) {
        throw new \DomainException('Invalid melody settings.');
      }
      $score->addMelody();
      $score->setMelodyPattern($index, $settings['pattern']);
      if (array_key_exists('instrument', $settings)) {
        $instrument = $settings['instrument'];
        if (!is_string($instrument) || !Sound::valid($instrument)
          || (Sound::drumKey($instrument) !== null) !== in_array($settings['pattern'], Drum::NAMES, true)) {
          throw new \DomainException('Invalid melody instrument.');
        }
        $score->melodies[$index]['instrument'] = $instrument;
      }
      $score->setMelodyVolume($index, $settings['volume']);
      $score->setMelodyColor($index, $settings['color']);
      if (array_key_exists('muted', $settings) && !is_bool($settings['muted'])) {
        throw new \DomainException('Invalid melody mute setting.');
      }
      $score->setMelodyMuted($index, $settings['muted'] ?? false);
      if (array_key_exists('chordVoice', $settings)) {
        if (!is_int($settings['chordVoice']) || $settings['chordVoice'] < 0
          || $settings['chordVoice'] > 6
          || isset($chordVoices[$settings['chordVoice']])) {
          throw new \DomainException('Invalid chord voice.');
        }
        $chordVoices[$settings['chordVoice']] = true;
        $score->melodies[$index]['chordVoice'] = $settings['chordVoice'];
      }
      if (array_key_exists('generation', $settings)) {
        $score->melodies[$index]['generation'] = PhraseGenerator::validateMetadata($settings['generation']);
      }
      if (array_key_exists('motif', $settings)) {
        $motif = MotifScoreGenerator::validateMetadata($settings['motif']);
        if (isset($motifLabels[$motif['label']])) {
          throw new \DomainException('Duplicate motif label.');
        }
        $motifLabels[$motif['label']] = true;
        $score->melodies[$index]['motif'] = $motif;
      }
    }
    foreach (['title', 'author', 'catalog'] as $field) {
      if (!is_string($data[$field] ?? null) || !mb_check_encoding($data[$field], 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $data[$field])) {
        throw new \DomainException('Invalid score metadata.');
      }
      $score->$field = $data[$field];
    }
    $score->bars = [];
    foreach ($data['bars'] as $index => $bar) {
      if (!is_array($bar) || !is_int($bar['beats'] ?? null) || $bar['beats'] < 1 || $bar['beats'] > 32) {
        throw new \DomainException('Invalid bar beat count.');
      }
      $tempo = $bar['tempo'] ?? null;
      if (!is_string($tempo) || !mb_check_encoding($tempo, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $tempo)) {
        throw new \DomainException('Invalid bar tempo.');
      }
      $tempo = trim($tempo);
      if (!self::validTempo($tempo)) {
        throw new \DomainException('Invalid bar tempo.');
      }
      $leader = $bar['sectionLeader'] ?? null;
      if (!is_bool($leader) || ($index === 0 && !$leader)) {
        throw new \DomainException('Invalid section leader.');
      }
      $repeats = $bar['repeats'] ?? 0;
      if (!is_int($repeats) || $repeats < 0 || $repeats > 8 || (!$leader && $repeats !== 0)) {
        throw new \DomainException('Invalid section repeat count.');
      }
      $note = $bar['note'] ?? '';
      if (!is_string($note) || !mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 200
        || preg_match('/[\x00-\x1f\x7f]/', $note)) {
        throw new \DomainException('Invalid bar note.');
      }
      $savedBar = ['beats' => $bar['beats'], 'tempo' => $tempo,
        'sectionLeader' => $leader, 'repeats' => $repeats, 'note' => $note];
      if (array_key_exists('music', $bar)) {
        if (!$leader) {
          throw new \DomainException('Harmonic context belongs on a section start.');
        }
        $savedBar['music'] = HarmonicContext::validate($bar['music']);
      }
      if (array_key_exists('motifVersion', $bar)) {
        if (!$leader || $index === 0) {
          throw new \DomainException('A generated motif version needs its own section.');
        }
        $savedBar['motifVersion'] = MotifVersions::validateSpec($bar['motifVersion'],
          $score->sectionNumber($index - 1));
      }
      $score->bars[] = $savedBar;
    }
    foreach ($data['notes'] as $inputNote) {
      if (!self::validNote($inputNote, $score)) {
        throw new \DomainException('Invalid score note.');
      }
      $articulation = $inputNote['articulation'] ?? 'ordinary';
      $slur = $inputNote['slurToNext'] ?? false;
      $glissando = $inputNote['glissandoToNext'] ?? false;
      $fermata = $inputNote['fermata'] ?? false;
      $trill = $inputNote['trill'] ?? 0;
      $finger = $inputNote['finger'] ?? '';
      $dynamic = $inputNote['dynamicToNext'] ?? '';
      $dynamicLevel = $inputNote['dynamicLevel'] ?? '';
      if (is_int($finger) && $finger >= 0 && $finger <= 5) {
        $finger = $finger === 0 ? '' : (string)$finger;
      }
      if (!in_array($articulation, ['ordinary', 'staccato'], true)
        || !is_bool($slur) || !is_bool($glissando) || !is_bool($fermata)
        || !is_int($trill) || !in_array($trill, [-2, -1, 0, 1, 2], true)
        || !self::validTrillPitch($inputNote, $trill)
        || !is_string($finger) || preg_match('/^(?:[LR][1-5]|[1-5])?$/D', $finger) !== 1
        || !is_string($dynamic) || !in_array($dynamic, ['', 'crescendo', 'decrescendo'], true)
        || !is_string($dynamicLevel) || !DynamicLevel::valid($dynamicLevel)
        || ($articulation === 'staccato' && ($slur || $glissando || $trill !== 0))
        || ($fermata && ($articulation === 'staccato' || $slur || $glissando))
        || ($glissando && $trill !== 0) || ($glissando && $slur)) {
        throw new \DomainException('Invalid note articulation.');
      }
      $note = array_intersect_key($inputNote, array_flip(['melody', 'bar', 'slot', 'octave', 'pitch', 'alter', 'length']));
      $note['articulation'] = $articulation;
      $note['slurToNext'] = $slur;
      $note['glissandoToNext'] = $glissando;
      $note['fermata'] = $fermata;
      $note['trill'] = $trill;
      $note['finger'] = $finger;
      $note['dynamicToNext'] = $dynamic;
      $note['dynamicLevel'] = $dynamicLevel;
      if (array_key_exists('figure', $inputNote)) {
        $figure = Figure::validate($inputNote['figure'], $note);
        if ($figure !== null) {
          if ($articulation !== 'ordinary' || $slur || $glissando || $fermata || $trill !== 0
            || in_array($score->melodies[$note['melody']]['pattern'], Drum::NAMES, true)) {
            throw new \DomainException('A figure needs an unmarked pitched note.');
          }
          $note['figure'] = $figure;
        }
      }
      if (array_key_exists('chord', $inputNote)) {
        $note['chord'] = ChordGenerator::validateMetadata($inputNote['chord']);
      }
      $score->assertFree($note, null);
      $score->assertMelodyBand($note['melody'], $note['octave'], Pitch::position($note));
      $score->notes[] = $note;
      $score->ensureDuration($note);
    }
    $shared = $data['scoreDynamics'] ?? [];
    if (!is_array($shared) || !array_is_list($shared)) {
      throw new \DomainException('Invalid shared dynamics.');
    }
    $previousTime = -1;
    foreach ($shared as $mark) {
      if (!is_array($mark) || !is_int($mark['time'] ?? null) || $mark['time'] <= $previousTime
        || $mark['time'] < 0 || $mark['time'] >= $score->start(count($score->bars))
        || !is_string($mark['level'] ?? null) || !is_string($mark['direction'] ?? null)
        || ($mark['level'] === '' && $mark['direction'] === '')
        || ($mark['level'] !== '' && !in_array($mark['level'], DynamicLevel::marks(), true))
        || !in_array($mark['direction'], ['', 'crescendo', 'decrescendo'], true)
        || (($mark['direction'] === '') !== (($mark['end'] ?? null) === null))
        || ($mark['direction'] !== '' && (!is_int($mark['end'] ?? null)
          || $mark['end'] <= $mark['time'] || $mark['end'] > $score->start(count($score->bars))))) {
        throw new \DomainException('Invalid shared dynamic mark.');
      }
      $score->scoreDynamics[] = ['time' => $mark['time'], 'level' => $mark['level'],
        'direction' => $mark['direction'], 'end' => $mark['end'] ?? null];
      $previousTime = $mark['time'];
    }
    foreach ($score->notes as $index => $note) {
      if (($note['glissandoToNext'] ?? false) && !$score->validGlissando($index, true)) {
        throw new \DomainException('Invalid score glissando.');
      }
    }
    $score->normalizeSlurs();
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
    return $note['melody'] >= 0 && $note['melody'] < count($score->melodies) && $note['bar'] >= 0 && $note['bar'] < count($score->bars)
      && $note['slot'] >= 0 && $note['slot'] < 2 * $score->bars[$note['bar']]['beats']
      && $note['octave'] >= 0 && $note['octave'] <= 8 && abs($note['alter']) <= 1
      && Pitch::position($note) >= 1 && Pitch::position($note) <= 12
      && $note['length'] >= 1 && $note['length'] <= 198;
  }
}
