<?php

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Example tasks, including the two the brief shows on the public page.
 *
 * Development data. Dates are relative to the current week so the timeline
 * always has something on screen rather than bars that scrolled into the past
 * months ago.
 */
class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()->orderBy('id')->value('id');
        $monday = now()->startOfWeek();

        $tasks = [
            [
                'title' => 'Sosialisasi Registrasi Mahasiswa',
                'description' => 'Kegiatan sosialisasi registrasi semester 2026.1.',
                'start' => 0, 'days' => 3,
                'status' => TaskStatus::InProgress,
                'priority' => Priority::High,
                'public' => true,
                'progress' => 60,
            ],
            [
                'title' => 'Follow Up Mahasiswa Semester 2026.1',
                'description' => 'Menghubungi mahasiswa yang belum registrasi atau belum membayar.',
                'start' => 3, 'days' => 9,
                'status' => TaskStatus::Planned,
                'priority' => Priority::Normal,
                'public' => true,
                'progress' => 0,
            ],
            [
                'title' => 'Monitoring Komentar Instagram',
                'description' => 'Memantau dan menindaklanjuti komentar yang masuk.',
                'start' => 1, 'days' => 5,
                'status' => TaskStatus::InProgress,
                'priority' => Priority::Normal,
                'public' => false,
                'progress' => 35,
            ],
            [
                'title' => 'Laporan Mingguan',
                'description' => 'Rekap penanganan mahasiswa dan tiket untuk pimpinan.',
                'start' => 5, 'days' => 2,
                'status' => TaskStatus::Planned,
                'priority' => Priority::Low,
                // Internal reporting — deliberately not published.
                'public' => false,
                'progress' => 0,
            ],
        ];

        foreach ($tasks as $task) {
            Task::updateOrCreate(['title' => $task['title']], [
                'description' => $task['description'],
                'start_date' => $monday->copy()->addDays($task['start']),
                'due_date' => $monday->copy()->addDays($task['start'] + $task['days']),
                'status' => $task['status']->value,
                'priority' => $task['priority']->value,
                'is_public' => $task['public'],
                'progress' => $task['progress'],
                'created_by' => $creator,
            ]);
        }
    }
}
