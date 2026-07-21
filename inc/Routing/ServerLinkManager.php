<?php

class ServerLinkManager
{
    public static function create(array $data, ?int $actorUserId): int
    {
        if (empty($data['name']) || empty($data['ingress_id']) || empty($data['egress_server_id'])) {
            throw new InvalidArgumentException('Link name, ingress and egress server are required');
        }
        return RoutingRepository::createLink($data, $actorUserId);
    }
}
