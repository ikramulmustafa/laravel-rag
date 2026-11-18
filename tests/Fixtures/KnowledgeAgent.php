<?php

declare(strict_types=1);

namespace Ikram\Rag\Tests\Fixtures;

use Ikram\Rag\Facades\Rag;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/** An agent wired the way the README tells users to wire one: a single Rag::tool(). */
final class KnowledgeAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer from the knowledge base and cite the sources you used.';
    }

    public function tools(): iterable
    {
        return [Rag::tool()];
    }
}
