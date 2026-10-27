<?php

namespace MADSCORE\Render;

use MADSCORE\Score\FigurePattern;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;

/** Resolve used motifs and give each letter its fixed appendix mark. */
final class FigureCatalog {
  private array $entries = [];
  private array $byKey = [];

  public function __construct(private Score $score, ?Project $project = null) {
    $notes = $score->visibleCopy()->notes;
    usort($notes, static fn (array $a, array $b): int =>
      [$score->start($a['bar']) + $a['slot'], $a['melody']]
      <=> [$score->start($b['bar']) + $b['slot'], $b['melody']]);
    foreach ($notes as $note) {
      $figure = $note['figure'] ?? null;
      if (!is_array($figure) || !isset($figure['motif'])) {
        continue;
      }
      $key = $figure['motif'];
      if (isset($this->byKey[$key])) {
        $index = $this->byKey[$key];
        $this->entries[$index]['types'][$figure['type']] = true;
        if ($this->entries[$index]['error'] === null) {
          $this->checkPitchRange($this->entries[$index], $note);
          $this->checkOrnamentRange($this->entries[$index]);
        }
        continue;
      }
      $index = count($this->entries);
      $letterIndex = ord($key) - ord('A');
      $entry = ['motif' => $key,
        'number' => $letterIndex + 1,
        'types' => [$figure['type'] => true], 'notes' => [], 'score' => null,
        'error' => null, 'ornamentError' => null];
      try {
        if ($project === null) {
          throw new \DomainException('Motif is unavailable.');
        }
        $entry['notes'] = FigurePattern::notes($project, $key);
        $entry['score'] = $project->score($key);
        $this->checkPitchRange($entry, $note);
        $this->checkOrnamentRange($entry);
      } catch (\Throwable $error) {
        $entry['error'] = 'Invalid figure: ' . $error->getMessage();
      }
      $this->byKey[$key] = $index;
      $this->entries[] = $entry;
    }
  }

  public function entries(): array {
    return $this->entries;
  }

  public function score(): Score {
    return $this->score;
  }

  public function reference(array $figure): ?array {
    $index = $this->byKey[$figure['motif']] ?? null;
    return $index === null ? null : $this->entries[$index];
  }

  private function checkPitchRange(array &$entry, array $note): void {
    $base = 12 * $note['octave'] + Pitch::position($note);
    foreach ($entry['notes'] as $part) {
      if ($base + $part['offset'] < 1 || $base + $part['offset'] > 108) {
        $entry['error'] = 'Invalid figure: the motif goes outside the score at a marked note.';
        return;
      }
    }
  }

  private function checkOrnamentRange(array &$entry): void {
    if (!isset($entry['types']['ornament'])) {
      return;
    }
    foreach ($entry['notes'] as $part) {
      if ($part['offset'] < -2 || $part['offset'] > 2) {
        $entry['ornamentError'] = 'Invalid ornament: notes must stay within -2 to +2 semitones.';
        return;
      }
    }
  }

}
