<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Period;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class CanvasSyncSisCommand extends Command
{
    protected $signature = 'canvas:sync-sis {--dry-run : 仅生成本地 CSV 和 ZIP 文件，不上传至 Canvas API}';

    protected $description = '按 Canvas LMS 规范导出 Academico 核心教务数据 (学员/教师/学期/课程/选课) 并推送至 Canvas SIS Import API';

    public function handle(): int
    {
        $this->info('=============================================');
        $this->info('  Academico -> Canvas LMS SIS 数据同步流水线  ');
        $this->info('=============================================');

        $tempDir = storage_path('app/canvas_sis_export');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $authProvider = config('services.canvas.auth_provider', 'saml');

        // 1. 生成 users.csv (包含全部学员和授课教师)
        $this->info('>>> [1/5] 生成 users.csv ...');
        $usersFile = "{$tempDir}/users.csv";
        $fp = fopen($usersFile, 'w');
        fputcsv($fp, ['user_id', 'login_id', 'first_name', 'last_name', 'email', 'status', 'authentication_provider_id']);

        $userCount = 0;
        User::withTrashed()->cursor()->each(function (User $user) use ($fp, $authProvider, &$userCount) {
            // 仅同步学生或教师，排除未分配身份的测试账号
            if (! $user->isStudent() && ! $user->isTeacher() && ! $user->hasRole('admin')) {
                return;
            }

            if (empty($user->email)) {
                return;
            }

            $userId = "ACA-U{$user->id}";
            $loginId = strtolower(trim($user->email)); // 与 Entra ID / Canvas SAML 统一匹配规则
            $firstName = $user->firstname ?: 'User';
            $lastName = $user->lastname ?: (string) $user->id;
            $status = $user->trashed() ? 'deleted' : 'active';

            fputcsv($fp, [
                $userId,
                $loginId,
                $firstName,
                $lastName,
                $loginId,
                $status,
                $authProvider,
            ]);
            $userCount++;
        });
        fclose($fp);
        $this->line("    已导出用户数: {$userCount}");

        // 2. 生成 terms.csv (映射 Academico 的 Period/学期)
        $this->info('>>> [2/5] 生成 terms.csv ...');
        $termsFile = "{$tempDir}/terms.csv";
        $fp = fopen($termsFile, 'w');
        fputcsv($fp, ['term_id', 'name', 'status', 'start_date', 'end_date']);

        $termCount = 0;
        Period::cursor()->each(function (Period $period) use ($fp, &$termCount) {
            $termId = "ACA-P{$period->id}";
            fputcsv($fp, [
                $termId,
                $period->name,
                'active',
                $period->start ? $period->start->format('Y-m-d') : '',
                $period->end ? $period->end->format('Y-m-d') : '',
            ]);
            $termCount++;
        });
        fclose($fp);
        $this->line("    已导出学期数: {$termCount}");

        // 3. 生成 courses.csv (真实开课课程)
        $this->info('>>> [3/5] 生成 courses.csv ...');
        $coursesFile = "{$tempDir}/courses.csv";
        $fp = fopen($coursesFile, 'w');
        fputcsv($fp, ['course_id', 'short_name', 'long_name', 'term_id', 'status']);

        $courseCount = 0;
        Course::realcourses()->cursor()->each(function (Course $course) use ($fp, &$courseCount) {
            $courseId = "ACA-C{$course->id}";
            $shortName = $course->shortname ?: "COURSE-{$course->id}";
            $longName = $course->name ?: $shortName;
            $termId = $course->period_id ? "ACA-P{$course->period_id}" : '';

            fputcsv($fp, [
                $courseId,
                $shortName,
                $longName,
                $termId,
                'active',
            ]);
            $courseCount++;
        });
        fclose($fp);
        $this->line("    已导出课程数: {$courseCount}");

        // 4. 生成 sections.csv (1门课程对应1个主班级 section)
        $this->info('>>> [4/5] 生成 sections.csv ...');
        $sectionsFile = "{$tempDir}/sections.csv";
        $fp = fopen($sectionsFile, 'w');
        fputcsv($fp, ['section_id', 'course_id', 'name', 'status']);

        Course::realcourses()->cursor()->each(function (Course $course) use ($fp) {
            $sectionId = "ACA-S{$course->id}";
            $courseId = "ACA-C{$course->id}";
            fputcsv($fp, [
                $sectionId,
                $courseId,
                $course->name ?: "Default Section",
                'active',
            ]);
        });
        fclose($fp);

        // 5. 生成 enrollments.csv (学员选课状态与主讲教师身份绑定)
        $this->info('>>> [5/5] 生成 enrollments.csv ...');
        $enrollmentsFile = "{$tempDir}/enrollments.csv";
        $fp = fopen($enrollmentsFile, 'w');
        fputcsv($fp, ['course_id', 'user_id', 'role', 'status', 'section_id']);

        $enrollmentCount = 0;

        // 5.1 导出学生选课 (status_id 1或2 为确认/付款状态，其余状态标记为 deleted)
        Enrollment::with(['student.user', 'course'])->cursor()->each(function (Enrollment $enrollment) use ($fp, &$enrollmentCount) {
            if (! $enrollment->course_id || ! $enrollment->student_id) {
                return;
            }

            $courseId = "ACA-C{$enrollment->course_id}";
            $sectionId = "ACA-S{$enrollment->course_id}";
            $userId = "ACA-U{$enrollment->student_id}";

            // Academico 状态逻辑：1=待付款(pending), 2=已付款(paid)
            $isActive = in_array((string) $enrollment->status_id, Enrollment::ENROLLMENT_STATUSES_TO_COUNT_IN_STATS, true);
            $status = $isActive ? 'active' : 'deleted';

            fputcsv($fp, [
                $courseId,
                $userId,
                'student',
                $status,
                $sectionId,
            ]);
            $enrollmentCount++;
        });

        // 5.2 导出课程授课教师身份
        Course::realcourses()->with('teacher.user')->cursor()->each(function (Course $course) use ($fp, &$enrollmentCount) {
            if (! $course->teacher_id) {
                return;
            }

            $courseId = "ACA-C{$course->id}";
            $sectionId = "ACA-S{$course->id}";
            $userId = "ACA-U{$course->teacher_id}";

            fputcsv($fp, [
                $courseId,
                $userId,
                'teacher',
                'active',
                $sectionId,
            ]);
            $enrollmentCount++;
        });

        fclose($fp);
        $this->line("    已导出选课/授课记录数: {$enrollmentCount}");

        // 6. 打包为 ZIP 压缩包
        $zipPath = storage_path('app/canvas_sis_upload.zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('无法创建 SIS 上传压缩包');
            return self::FAILURE;
        }

        $files = ['users.csv', 'terms.csv', 'courses.csv', 'sections.csv', 'enrollments.csv'];
        foreach ($files as $file) {
            $zip->addFile("{$tempDir}/{$file}", $file);
        }
        $zip->close();
        $this->info(">>> CSV 打包成功: {$zipPath} (" . round(filesize($zipPath) / 1024, 2) . " KB)");

        if ($this->option('dry-run')) {
            $this->warn('当前为 Dry-run 模拟模式，已跳过 API 上传。');
            return self::SUCCESS;
        }

        // 7. 调用 Canvas SIS Imports REST API 推送数据
        $this->info('>>> 推送数据至 Canvas LMS SIS Import API ...');
        $domain = config('services.canvas.domain');
        $token = config('services.canvas.token');
        $accountId = config('services.canvas.account_id', 1);
        $diffingDataset = config('services.canvas.diffing_dataset_id', 'academico_master_feed');

        if (empty($domain) || empty($token)) {
            $this->error('错误: 未配置 CANVAS_DOMAIN 或 CANVAS_API_TOKEN，请检查 .env 文件。');
            return self::FAILURE;
        }

        $apiUrl = "https://{$domain}/api/v1/accounts/{$accountId}/sis_imports";

        try {
            $response = Http::withToken($token)
                ->timeout(120)
                ->attach('attachment', file_get_contents($zipPath), 'canvas_sis_upload.zip')
                ->post($apiUrl, [
                    'import_type' => 'instructure_csv',
                    'diffing_data_set_identifier' => $diffingDataset,
                    'diffing_drop_status' => 'inactive', // 避免彻底删除时丢失学生历史作业和成绩数据
                ]);

            if ($response->successful()) {
                $jobId = $response->json('id');
                $progress = $response->json('progress', 0);
                $this->info("✅ SIS 导入任务已成功提交至 Canvas! 任务 ID: [{$jobId}], 初始进度: {$progress}%");
                Log::info("Canvas SIS Import dispatched successfully: ID {$jobId}");
                return self::SUCCESS;
            }

            $this->error("Canvas API 错误 [{$response->status()}]: " . $response->body());
            Log::error('Canvas SIS Import API Error: ' . $response->body());
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('网络请求异常: ' . $e->getMessage());
            Log::error('Canvas SIS Import Exception: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
