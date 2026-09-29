<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;

class AuditSummary
{
    public static function userDisplayName(?User $user): string
    {
        if (! $user) {
            return 'System / Guest';
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($user->email ?? 'Unknown user')) ?: 'Unknown user';
    }

    public static function recordSummary(AuditLog $log): string
    {
        $values = is_array($log->new_values) ? $log->new_values : [];
        $old = is_array($log->old_values) ? $log->old_values : [];
        $action = (string) $log->action;

        return match (true) {
            str_starts_with($action, 'auth.') => self::authSummary($action, $values),
            $action === 'student.created',
            $action === 'student.deleted' => self::personSummary($action === 'student.deleted' ? $old : $values, 'Student'),
            str_starts_with($action, 'admission.') => self::admissionSummary($action, $values, $old),
            str_starts_with($action, 'user.') => self::userSummary($action, $values, $old, $log),
            str_starts_with($action, 'grade') => self::gradeSummary($action, $values, $log),
            str_starts_with($action, 'system.branding') => self::brandingSummary($action, $values),
            str_starts_with($action, 'system.backup') => self::backupSummary($action, $values),
            $action === 'system.audit_retention_updated' => self::retentionSummary($values),
            $action === 'system.audit_logs_pruned' => self::pruneSummary($values),
            default => self::fallback($log),
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function authSummary(string $action, array $values): string
    {
        $login = trim((string) ($values['login'] ?? ''));

        return match ($action) {
            'auth.login' => $login !== '' ? "Signed in as {$login}" : 'Signed in',
            'auth.logout' => $login !== '' ? "Signed out ({$login})" : 'Signed out',
            'auth.login_failed' => $login !== '' ? "Failed login for {$login}" : 'Failed login attempt',
            'auth.login_blocked' => $login !== '' ? "Blocked login for {$login}" : 'Blocked login',
            default => $login !== '' ? $login : '—',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function personSummary(array $values, string $noun): string
    {
        $name = trim((string) ($values['name'] ?? ''));
        $no = trim((string) ($values['student_no'] ?? $values['employee_no'] ?? ''));
        $bits = array_values(array_filter([$name, $no !== '' ? "No. {$no}" : '']));

        if ($bits === []) {
            return $noun.' record';
        }

        return implode(' · ', $bits);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $old
     */
    private static function admissionSummary(string $action, array $values, array $old): string
    {
        $src = $values !== [] ? $values : $old;
        $number = trim((string) ($src['admission_number'] ?? ''));
        $student = trim((string) ($src['student_name'] ?? $src['name'] ?? ''));
        $studentNo = trim((string) ($src['student_no'] ?? ''));
        $status = trim((string) ($src['status'] ?? ''));
        $oldStatus = trim((string) ($old['status'] ?? ''));

        $bits = [];
        if ($number !== '') {
            $bits[] = $number;
        }
        if ($student !== '') {
            $bits[] = $student;
        } elseif ($studentNo !== '') {
            $bits[] = "Student {$studentNo}";
        }
        if ($action === 'admission.status_updated' && ($oldStatus !== '' || $status !== '')) {
            $bits[] = trim($oldStatus.' → '.$status, ' →');
        } elseif ($status !== '' && $action === 'admission.created') {
            $bits[] = ucfirst($status);
        }
        if ($action === 'admission.subjects_enrolled') {
            $count = is_array($src['class_section_ids'] ?? null) ? count($src['class_section_ids']) : 0;
            if ($count > 0) {
                $bits[] = $count.' subject'.($count === 1 ? '' : 's');
            }
        }

        return $bits !== [] ? implode(' · ', $bits) : 'Admission record';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $old
     */
    private static function userSummary(string $action, array $values, array $old, AuditLog $log): string
    {
        $src = $values !== [] ? $values : $old;
        $name = trim((string) ($src['name'] ?? ''));
        $email = trim((string) ($src['email'] ?? $src['login'] ?? ''));
        $role = trim((string) ($src['role'] ?? ''));

        $bits = array_values(array_filter([
            $name,
            $email,
            $role !== '' ? ucfirst($role) : '',
        ]));

        if ($bits !== []) {
            return implode(' · ', $bits);
        }

        if ($log->auditable_id) {
            return 'User #'.$log->auditable_id;
        }

        return 'User account';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function gradeSummary(string $action, array $values, AuditLog $log): string
    {
        $student = trim((string) ($values['student_name'] ?? $values['student_no'] ?? ''));
        $subject = trim((string) ($values['subject_code'] ?? $values['subject'] ?? ''));
        $period = trim((string) ($values['period'] ?? ''));
        $bits = array_values(array_filter([$student, $subject, $period !== '' ? ucfirst(str_replace('_', ' ', $period)) : '']));

        if ($bits !== []) {
            return implode(' · ', $bits);
        }

        if ($log->auditable_id) {
            $short = class_basename((string) $log->auditable_type) ?: 'Record';

            return "{$short} #{$log->auditable_id}";
        }

        return 'Grade record';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function brandingSummary(string $action, array $values): string
    {
        return match ($action) {
            'system.branding_updated' => trim((string) ($values['system_name'] ?? 'School name & texts')),
            'system.branding_logo_updated' => 'System logo',
            'system.branding_favicon_updated' => 'Favicon',
            'system.branding_login_bg_updated' => 'Login background',
            'system.branding_logo_cleared' => 'Default logo restored',
            'system.branding_favicon_cleared' => 'Default favicon restored',
            'system.branding_login_bg_cleared' => 'Default login background restored',
            'system.branding_reset' => 'All school info defaults',
            default => 'School info',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function backupSummary(string $action, array $values): string
    {
        $name = trim((string) ($values['name'] ?? ''));

        return match ($action) {
            'system.backup_created', 'system.backup_scheduled' => $name !== '' ? $name : 'Database backup',
            'system.backup_deleted' => $name !== '' ? $name : 'Backup file',
            'system.backup_schedule_updated' => 'Backup schedule',
            default => $name !== '' ? $name : 'Backup',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function retentionSummary(array $values): string
    {
        if (! ($values['enabled'] ?? true)) {
            return 'Auto-delete turned off';
        }
        $label = trim((string) ($values['label'] ?? ''));

        return $label !== '' ? "Keep logs for {$label}" : 'Auto-delete schedule';
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function pruneSummary(array $values): string
    {
        $deleted = (int) ($values['deleted'] ?? 0);
        $label = trim((string) ($values['label'] ?? ''));
        if ($label === '' && isset($values['days'])) {
            $label = ((int) $values['days']).' days';
        }

        return $deleted.' removed'.($label !== '' ? " (older than {$label})" : '');
    }

    private static function fallback(AuditLog $log): string
    {
        if ($log->auditable_type && $log->auditable_id) {
            return class_basename($log->auditable_type).' #'.$log->auditable_id;
        }

        return '—';
    }
}
