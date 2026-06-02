<?php

namespace App\Pai\Cognition;

use App\Pai\Cognition\Tools\DevAuto\ListRepoFilesTool;
use App\Pai\Cognition\Tools\DevAuto\ProposePatchTool;
use App\Pai\Cognition\Tools\DevAuto\ReadRepoFileTool;
use App\Pai\Cognition\Tools\DevAuto\RunTestsTool;
use App\Pai\Cognition\Tools\LogOps\MatchRunbookTool;
use App\Pai\Cognition\Tools\LogOps\ProposeRemediationTool;
use App\Pai\Cognition\Tools\LogOps\ReadLogContextTool;
use App\Pai\Cognition\Tools\SecIr\LookupAttackTechniqueTool;
use App\Pai\Cognition\Tools\SecIr\OpenIncidentTicketTool;
use App\Pai\Cognition\Tools\SecIr\ProposeContainmentTool;
use App\Pai\Cognition\Tools\SecIr\QueryEndpointTool;
use App\Pai\Security\EgressGateway;
use App\Pai\Security\Sandbox;

/**
 * 依領域提供專屬工具（L4）。基礎工具由 CognitiveEngine 提供給所有協調者；
 * 此處疊加各領域的真實能力。未來可改由領域包的 mcp:// 宣告動態解析。
 */
class DomainToolset
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly EgressGateway $egress,
    ) {}

    /**
     * @return list<Tool>
     */
    public function for(string $domain): array
    {
        return match ($domain) {
            'dev-auto' => $this->devAuto(),
            'sec-ir' => $this->secIr(),
            'log-ops' => [new ReadLogContextTool, new MatchRunbookTool, new ProposeRemediationTool],
            default => [],
        };
    }

    /** @return list<Tool> */
    private function secIr(): array
    {
        return [
            new LookupAttackTechniqueTool,
            new QueryEndpointTool($this->egress, config('pai.secir.edr_url')),
            new OpenIncidentTicketTool,
            new ProposeContainmentTool,
        ];
    }

    /** @return list<Tool> */
    private function devAuto(): array
    {
        $repo = (string) config('pai.devauto.repo_path');
        $entry = (string) config('pai.devauto.test_entry');

        return [
            new ListRepoFilesTool($repo),
            new ReadRepoFileTool($repo),
            new RunTestsTool($this->sandbox, $repo, $entry),
            new ProposePatchTool,
        ];
    }
}
