<?php

declare(strict_types=1);

namespace App\Quest\Live\Questions;

use App\Quest\Live\LiveContext;
use App\Quest\Live\LiveEngine;
use App\Quest\Live\LiveEvent;
use App\Quest\Live\LiveView;

/** One question per screen, then done. */
final class QuestionsEngine implements LiveEngine
{
    public function key(): string
    {
        return QuestionsSpec::KEY;
    }

    public function open(LiveContext $context): array
    {
        return ['at' => 0, 'answers' => [], 'count' => count($this->questions($context))];
    }

    public function handle(array $state, LiveEvent $event, LiveContext $context): array
    {
        $question = $this->questions($context)[$state['at']] ?? null;
        if ($question === null || $event->kind !== 'answer' || $event->widget() !== $question['id']) {
            return $state;
        }
        $answer = $question['kind'] === 'number' ? $event->integer() : $event->text();
        $valid = $question['kind'] === 'number'
            ? $answer !== null && $answer >= ($question['min'] ?? 0) && $answer <= ($question['max'] ?? 100)
            : in_array($answer, array_column($question['options'], 'value'), true);
        if (!$valid) {
            return $state;
        }
        $state['answers'][$question['id']] = $answer;
        ++$state['at'];

        return $state;
    }

    public function advance(array $state, LiveContext $context): array
    {
        return $state;
    }

    public function view(array $state, LiveContext $context): LiveView
    {
        $question = $this->questions($context)[$state['at']] ?? null;
        if ($question === null) {
            return (new LiveView('done', 'Thank you', 10_000))->text('Your answers are saved.', 'success');
        }
        $view = new LiveView('ask', sprintf('Question %d of %d', $state['at'] + 1, $state['count']), 10_000);
        if ($question['kind'] === 'number') {
            $min = (int) ($question['min'] ?? 0);

            return $view->number($question['id'], $question['prompt'], $min, (int) ($question['max'] ?? 100), $min, $question['record'] ?? null);
        }

        return $view->choice($question['id'], $question['prompt'], array_values($question['options']));
    }

    public function isDone(array $state): bool
    {
        return $state['at'] >= $state['count'];
    }

    public function result(array $state): array
    {
        return ['schema' => 'monad-live/questions-result/v1', 'answers' => $state['answers']];
    }

    /** @return list<array<string, mixed>> */
    private function questions(LiveContext $context): array
    {
        return array_values((array) ($context->options['questions'] ?? []));
    }
}
