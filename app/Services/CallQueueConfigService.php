<?php

namespace App\Services;

use App\Models\CallQueue;
use DOMDocument;

class CallQueueConfigService
{
    /** @param iterable<CallQueue> $queues */
    public function build(iterable $queues): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->appendChild($document->createElement('configuration'));
        $root->setAttribute('name', 'callcenter.conf');
        $root->setAttribute('description', 'Blucom call teams');
        $root->appendChild($document->createElement('settings'));
        $queueRoot = $root->appendChild($document->createElement('queues'));

        foreach ($queues as $record) {
            $queue = $queueRoot->appendChild($document->createElement('queue'));
            $queue->setAttribute('name', $record->freeSwitchName());
            foreach ([
                'strategy' => $record->strategy,
                'moh-sound' => 'local_stream://moh',
                'time-base-score' => 'queue',
                'max-wait-time' => (string) $record->max_wait_seconds,
                'max-wait-time-with-no-agent' => '15',
                'max-wait-time-with-no-agent-time-reached' => '5',
                'agent-no-answer-status' => 'Available',
                'skip-agents-with-external-calls' => 'true',
            ] as $name => $value) {
                $param = $queue->appendChild($document->createElement('param'));
                $param->setAttribute('name', $name);
                $param->setAttribute('value', $value);
            }
        }

        $root->appendChild($document->createElement('agents'));
        $root->appendChild($document->createElement('tiers'));

        return $document->saveXML();
    }
}
