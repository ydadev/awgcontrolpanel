<?php

class RoutingAgentClient
{
    public static function isConfigured(array $serverState): bool
    {
        return !empty($serverState['agent_endpoint']);
    }

    public static function apply(array $serverState, array $configuration): array
    {
        return [
            'success' => false,
            'error' => 'Routing agent delivery is not enabled in phase 1',
        ];
    }
}
