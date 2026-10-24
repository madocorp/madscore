<?php

namespace MADSCORE\Score;

use MADSCORE\Play\Drum;

/** A motif normalized to its first note for a figure mark. */
final class FigurePattern {
  public static function notes(Project $project, string $id): array {
    $motif = $project->score($id);
    if ($motif->notes === []) {
      throw new \DomainException('the motif has no notes.');
    }
    $melodies = array_unique(array_column($motif->notes, 'melody'));
    if (count($melodies) !== 1) {
      throw new \DomainException('the motif has notes in more than one melody.');
    }
    if (in_array($motif->melodies[$melodies[0]]['pattern'], Drum::NAMES, true)) {
      throw new \DomainException('the motif has pitchless notes.');
    }
    $notes = $motif->visibleCopy()->notes;
    if (count($notes) !== count($motif->notes)) {
      throw new \DomainException('the motif contains muted notes.');
    }
    usort($notes, static fn (array $a, array $b): int =>
      ($motif->start($a['bar']) + $a['slot']) <=> ($motif->start($b['bar']) + $b['slot']));
    $firstTime = $motif->start($notes[0]['bar']) + $notes[0]['slot'];
    $firstPitch = $notes[0]['octave'] * 12 + Pitch::position($notes[0]);
    $result = [];
    foreach ($notes as $note) {
      if (isset($note['figure'])) {
        throw new \DomainException('nested figures are unsupported.');
      }
      if ($note['length'] < 1) {
        throw new \DomainException('the motif contains an invalid duration.');
      }
      $result[] = ['time' => $motif->start($note['bar']) + $note['slot'] - $firstTime,
        'length' => $note['length'], 'offset' => $note['octave'] * 12 + Pitch::position($note) - $firstPitch,
        'black' => Pitch::isBlack(Pitch::position($note))];
    }
    return $result;
  }
}
