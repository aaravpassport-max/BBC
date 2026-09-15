<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\GrievanceRepository;
use RTOFLOW\Support\EventBus;

if (!defined('ABSPATH')) exit;

class GrievanceService
{
    public function __construct(
        private GrievanceRepository $repo,
        private EventBus            $events
    ) {}

    public function create(array $data): array
    {
        if (empty($data['subject']) || empty($data['complaint_type']) || empty($data['description'])) {
            return ['success' => false, 'message' => 'Subject, type, and description are required'];
        }

        $type = $data['complaint_type'];
        $sla  = GrievanceRepository::SLA[$type] ?? 48;
        $num  = $this->repo->next_number();

        $id = $this->repo->create([
            'complaint_number' => $num,
            'complaint_type'   => $type,
            'lead_id'          => $data['lead_id'] ?? null,
            'complainant_id'   => get_current_user_id(),
            'subject'          => sanitize_text_field($data['subject']),
            'description'      => sanitize_textarea_field($data['description']),
            'priority'         => match($type) {
                'fraud', 'misconduct' => 5,
                'payment', 'refund'   => 4,
                default               => 2,
            },
            'sla_deadline'     => date('Y-m-d H:i:s', strtotime("+{$sla} hours")),
        ]);

        $this->events->fire('complaint.created', ['complaint_id' => $id, 'type' => $type]);
        return ['success' => true, 'complaint_id' => $id, 'complaint_number' => $num];
    }

    public function update_status(int $id, string $status, string $note = ''): bool
    {
        $data = ['status' => $status];
        if ($note) {
            $data['resolution_note'] = $note;
        }
        if (in_array($status, ['resolved', 'closed'], true)) {
            $data['resolved_at'] = current_time('mysql');
        }
        return $this->repo->update($id, $data);
    }
}
