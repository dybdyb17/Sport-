<?php

namespace App\EventListener;

use App\Entity\Enum\AuditAction;
use App\Entity\User;
use App\Service\AuditLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Trace chaque impersonnification (admin → user et retour) dans le journal d'audit.
 *
 * Symfony émet `SwitchUserEvent` deux fois :
 * - Une fois quand l'admin entre dans l'espace d'un user (cible = user impersonné)
 * - Une fois quand l'admin en sort via `_switch_user=_exit` (cible = admin lui-même)
 *
 * On distingue les deux via la présence du token SwitchUserToken et la cible.
 */
#[AsEventListener(event: 'security.switch_user')]
class SwitchUserAuditListener
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(SwitchUserEvent $event): void
    {
        $target = $event->getTargetUser();
        if (!$target instanceof User) {
            return;
        }

        $request = $event->getRequest();
        $param   = (string) $request->query->get('_switch_user', '');

        // Détection : c'est une SORTIE si le paramètre vaut "_exit",
        // sinon c'est une ENTRÉE.
        $action = $param === '_exit'
            ? AuditAction::ADMIN_LEAVE_IMPERSONATE
            : AuditAction::ADMIN_IMPERSONATE;

        $this->auditLogger->log($action, $target, [
            'target_email'    => $target->getEmail(),
            'target_role'     => $target->getRole()->value,
            'switch_param'    => $param,
        ]);
    }
}
