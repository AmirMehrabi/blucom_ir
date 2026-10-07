<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Services\Commerce\LineEntitlementService;
use DOMDocument;

class CallQueueConfigService
{
    public function eligibleQueues()
    {
        return CallQueue::query()->where('enabled', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->with(['tenant', 'members' => fn ($query) => $query->where('enabled', true)])
            ->orderBy('id')->get()->filter(fn ($queue) => app(LineEntitlementService::class)->tenantAllows($queue->tenant, true)
                && $queue->members->isNotEmpty()
                && $queue->members->every(fn ($member) => $member->tenant_id === $queue->tenant_id));
    }

    public function lookup(array $request): string
    {
        if (! config('voip.queues_enabled') || ($request['key_value'] ?? null) !== 'callcenter.conf'
            || ! in_array($request['tag_name'] ?? '', ['', 'configuration'], true)
            || ! in_array($request['key_name'] ?? '', ['', 'name'], true)) {
            return app(FreeSwitchDirectoryService::class)->notFound();
        }
        $queues = $this->eligibleQueues();
        if (isset($request['CC-Queue'])) {
            if (! is_string($request['CC-Queue']) || ! preg_match('/^blucom_q_[1-9][0-9]*@default$/D', $request['CC-Queue'])) {
                return app(FreeSwitchDirectoryService::class)->notFound();
            }
            $queues = $queues->filter(fn ($queue) => $queue->freeSwitchName() === $request['CC-Queue']);
        }
        $configuration = new DOMDocument;
        $configuration->loadXML($this->build($queues));
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElement('document'));
        $root->setAttribute('type', 'freeswitch/xml');
        $section = $root->appendChild($document->createElement('section'));
        $section->setAttribute('name', 'configuration');
        $section->appendChild($document->importNode($configuration->documentElement, true));

        return $document->saveXML();
    }

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
