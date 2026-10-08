<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\GradeType;
use App\Models\Level;
use App\Models\Period;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Year;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class JupiterImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jupiter:import 
                            {path : 存放 JupiterEd CSV 导出文件的目录绝对路径}
                            {--dry-run : 模拟导入，校验并打印分析结果，不写入数据库}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '从 Jupiter Ed 导出的 CSV 文件中导入学生、教师、课程、选课及成绩数据';

    public function handle(): int
    {
        $path = rtrim($this->argument('path'), '/\\');
        $isDryRun = $this->option('dry-run');

        if (! is_dir($path)) {
            $this->error("指定目录不存在: {$path}");
            return Command::FAILURE;
        }

        $this->info("=== Jupiter Ed 数据导入工具 ===");
        $this->info("数据目录: {$path}");
        if ($isDryRun) {
            $this->warn("【演练模式 (DRY RUN)】不会向数据库写入任何真实变更。");
        }

        // 查找文件
        $staffFile = $this->findFile($path, ['staff.csv', 'staff']);
        $studentsFile = $this->findFile($path, ['students_VAT.csv', 'students.csv', 'student']);
        $classesFile = $this->findFile($path, ['classes_VAT.csv', 'classes.csv', 'class']);
        $gradesFile = $this->findFile($path, ['grades_VAT.csv', 'grades.csv', 'grade']);

        if (! $staffFile || ! $studentsFile || ! $classesFile) {
            $this->error("未能在目录下找到必需的 CSV 文件。");
            $this->line("需要：staff.csv, students_*.csv, classes_*.csv (可选 grades_*.csv)");
            return Command::FAILURE;
        }

        $this->info("找到文件：");
        $this->line("- 教职工文件: " . basename($staffFile));
        $this->line("- 学生文件:   " . basename($studentsFile));
        $this->line("- 课程文件:   " . basename($classesFile));
        $this->line("- 成绩文件:   " . ($gradesFile ? basename($gradesFile) : "未找到(跳过成绩)"));

        // 解析 CSV 数据
        $staffData = $this->parseCsv($staffFile);
        $studentsData = $this->parseCsv($studentsFile);
        $classesData = $this->parseCsv($classesFile);
        $gradesData = $gradesFile ? $this->parseCsv($gradesFile) : [];

        $this->newLine();
        $this->info("读取到原始数据条数：");
        $this->line("- 教职工: " . count($staffData) . " 条");
        $this->line("- 学生:   " . count($studentsData) . " 条");
        $this->line("- 课程:   " . count($classesData) . " 条");
        $this->line("- 选课/成绩: " . count($gradesData) . " 条");

        if ($isDryRun) {
            $this->info("\nDry run 校验通过，数据格式完备。可以执行 live 导入。");
            return Command::SUCCESS;
        }

        DB::beginTransaction();
        try {
            // 1. 学年与学期 (Year & Period)
            $this->info("\n[1/6] 创建/对齐学年与学期...");
            $year = Year::firstOrCreate(['name' => '2026-2027']);
            
            $periodYr = Period::firstOrCreate(
                ['name' => '2026-2027 (Yr)', 'year_id' => $year->id],
                [
                    'start' => '2026-09-01',
                    'end' => '2027-06-30',
                ]
            );

            $periodS1 = Period::firstOrCreate(
                ['name' => '2026-2027 Fall (S1)', 'year_id' => $year->id],
                [
                    'start' => '2026-09-01',
                    'end' => '2027-01-31',
                ]
            );

            $campus = Campus::firstOrCreate(['id' => 1], ['name' => 'Main Campus']);

            // 2. 年级级别 (Levels)
            $this->info("[2/6] 创建/对齐年级级别 (Levels)...");
            $levelsMap = [];
            foreach (['10' => 'Grade 10', '11' => 'Grade 11', '12' => 'Grade 12'] as $k => $lvlName) {
                $level = Level::firstOrCreate(['name' => $lvlName]);
                $levelsMap[$k] = $level->id;
                $levelsMap[$lvlName] = $level->id;
            }

            // 3. 教职工/教师 (Staff -> Users + Teachers)
            $this->info("[3/6] 导入教职工与任课教师...");
            $teachersMap = []; // StaffID => teacher_id (user_id)
            foreach ($staffData as $row) {
                $staffId = trim($row['StaffID'] ?? '');
                $email = strtolower(trim($row['Email'] ?? ''));
                if (empty($email)) continue;

                $firstName = trim($row['FirstName'] ?? '');
                $lastName = trim($row['LastName'] ?? '');
                $username = strtolower(explode('@', $email)[0]);

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'username' => $username,
                        'firstname' => $firstName,
                        'lastname' => $lastName,
                        'password' => Hash::make(Str::random(16)),
                    ]
                );

                $teacher = Teacher::firstOrCreate(['id' => $user->id]);

                if ($staffId) {
                    $teachersMap[$staffId] = $teacher->id;
                }
            }

            // 处理 classes 中可能出现的未在 staff.csv 登记的工号 (如 VIATY002)
            foreach ($classesData as $cRow) {
                $tList = explode(',', $cRow['Teacher'] ?? '');
                foreach ($tList as $tCode) {
                    $tCode = trim($tCode);
                    if ($tCode && ! isset($teachersMap[$tCode])) {
                        $placeholderEmail = strtolower($tCode) . '@yangfanschool.com';
                        $u = User::firstOrCreate(
                            ['email' => $placeholderEmail],
                            [
                                'username' => strtolower($tCode),
                                'firstname' => $tCode,
                                'lastname' => 'Teacher',
                                'password' => Hash::make(Str::random(16)),
                            ]
                        );
                        Teacher::firstOrCreate(['id' => $u->id]);
                        $teachersMap[$tCode] = $u->id;
                    }
                }
            }

            // 4. 学生档案 (Students -> Users + Students)
            $this->info("[4/6] 导入学生档案...");
            $studentsMap = []; // StudentID => student_id (user_id)
            foreach ($studentsData as $row) {
                $studentId = trim($row['StudentID'] ?? '');
                $email = strtolower(trim($row['StudentEmail'] ?? ''));
                if (empty($email)) continue;

                $firstName = trim($row['FirstName'] ?? '');
                $preferred = trim($row['PreferredName'] ?? '');
                $lastName = trim($row['LastName'] ?? '');
                $username = strtolower(explode('@', $email)[0]);

                // 若有英文常用名，则整合为 "Yifan (Ethan)" 方便在系统中识别
                $displayName = $preferred && ($preferred !== $firstName)
                    ? "{$firstName} ({$preferred})"
                    : $firstName;

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'username' => $username,
                        'firstname' => $displayName,
                        'lastname' => $lastName,
                        'password' => Hash::make(Str::random(16)),
                    ]
                );

                // 性别转换
                $genderId = match (strtoupper(trim($row['Gender'] ?? ''))) {
                    'F' => 1,
                    'M' => 2,
                    default => null,
                };

                // 出生日期
                $birthdate = null;
                $rawBdate = trim($row['Birthdate'] ?? '');
                if ($rawBdate && strlen($rawBdate) === 8) {
                    $birthdate = Carbon::createFromFormat('Ymd', $rawBdate)->format('Y-m-d');
                }

                Student::updateOrCreate(
                    ['id' => $user->id],
                    [
                        'idnumber' => $studentId,
                        'gender_id' => $genderId,
                        'birthdate' => $birthdate,
                    ]
                );

                if ($studentId) {
                    $studentsMap[$studentId] = $user->id;
                }
            }

            // 5. 课程开设 (Courses)
            $this->info("[5/6] 导入开课总表 (Courses)...");
            $coursesMap = []; // CourseTitle => Course Model
            foreach ($classesData as $row) {
                $courseTitle = trim($row['CourseTitle'] ?? '');
                if (empty($courseTitle)) continue;

                // 主讲教师
                $tList = explode(',', $row['Teacher'] ?? '');
                $primaryStaffCode = trim($tList[0] ?? '');
                $teacherId = $teachersMap[$primaryStaffCode] ?? null;

                // 开始与结束时间
                $startDate = '2026-09-01';
                $endDate = '2027-06-30';
                if (! empty($row['StartDate']) && strlen($row['StartDate']) === 8) {
                    $startDate = Carbon::createFromFormat('Ymd', $row['StartDate'])->format('Y-m-d');
                }
                if (! empty($row['EndDate']) && strlen($row['EndDate']) === 8) {
                    $endDate = Carbon::createFromFormat('Ymd', $row['EndDate'])->format('Y-m-d');
                }

                $course = Course::firstOrCreate(
                    [
                        'name' => $courseTitle,
                        'period_id' => $periodYr->id,
                    ],
                    [
                        'campus_id' => 1,
                        'teacher_id' => $teacherId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]
                );

                $coursesMap[$courseTitle] = $course;
            }

            // 6. 选课与成绩 (Enrollments & Grades)
            $this->info("[6/6] 导入选课名单与成绩评定...");
            
            // 创建成绩类型
            $gradeTypeS1 = GradeType::firstOrCreate(
                ['name' => 'Semester 1 (S1)'],
                ['total' => 100, 'grade_type_category_id' => 1]
            );

            $gradeTypeYr = GradeType::firstOrCreate(
                ['name' => 'Full Year (Yr)'],
                ['total' => 100, 'grade_type_category_id' => 1]
            );

            // 创建成绩评价类型与标准
            $evalType = \App\Models\EvaluationType::firstOrCreate(['name' => 'Standard Grades (S1 / Yr)']);
            $evalType->gradeTypes()->syncWithoutDetaching([$gradeTypeS1->id, $gradeTypeYr->id]);

            foreach ($coursesMap as $c) {
                if ($c->evaluation_type_id !== $evalType->id) {
                    $c->evaluation_type_id = $evalType->id;
                    $c->save();
                }
            }

            $enrollmentCount = 0;
            $gradeCount = 0;

            // 选课记录去重映射：StudentID_CourseTitle => Enrollment
            $enrollmentsMap = [];

            foreach ($gradesData as $row) {
                $studentId = trim($row['StudentID'] ?? '');
                $courseTitle = trim($row['CourseTitle'] ?? '');
                if (empty($studentId) || empty($courseTitle)) continue;

                $stuDbId = $studentsMap[$studentId] ?? null;
                $course = $coursesMap[$courseTitle] ?? null;

                if (! $stuDbId || ! $course) continue;

                $enrollKey = "{$stuDbId}_{$course->id}";
                if (! isset($enrollmentsMap[$enrollKey])) {
                    $enrollment = Enrollment::firstOrCreate(
                        [
                            'student_id' => $stuDbId,
                            'course_id' => $course->id,
                        ],
                        [
                            'status_id' => 1, // 已选课在读
                        ]
                    );

                    $enrollmentsMap[$enrollKey] = $enrollment;
                    $enrollmentCount++;
                } else {
                    $enrollment = $enrollmentsMap[$enrollKey];
                }

                // 记录成绩
                $term = trim($row['Term'] ?? '');
                $pct = trim($row['Pct'] ?? '');

                if ($pct !== '' && is_numeric($pct)) {
                    $numericGrade = floatval($pct);
                    $targetGradeType = ($term === 'S1') ? $gradeTypeS1 : $gradeTypeYr;

                    Grade::updateOrCreate(
                        [
                            'enrollment_id' => $enrollment->id,
                            'grade_type_id' => $targetGradeType->id,
                        ],
                        [
                            'grade' => $numericGrade,
                        ]
                    );
                    $gradeCount++;
                }
            }

            DB::commit();

            $this->newLine();
            $this->info("================ 导入成功报告 ================");
            $this->table(
                ['实体分类', '导入统计'],
                [
                    ['学年与学期 (Year / Periods)', '1 学年, 2 学期周期 (Yr / S1)'],
                    ['年级级别 (Levels)', count($levelsMap) / 2 . ' 个 (Grade 10, 11, 12)'],
                    ['教职工/教师 (Teachers)', count($teachersMap) . ' 位教师已建档并分配角色'],
                    ['学生档案 (Students)', count($studentsMap) . ' 位学生已建档'],
                    ['课程总表 (Courses)', count($coursesMap) . ' 门课程已开设'],
                    ['学生选课 (Enrollments)', "{$enrollmentCount} 笔选课入班已完成"],
                    ['历史成绩 (Grades)', "{$gradeCount} 条成绩记录已录入"],
                ]
            );

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("导入发生异常，已自动回滚全部数据库变更: " . $e->getMessage());
            $this->line($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * 在目录中按多种可能名称模糊查找文件
     */
    protected function findFile(string $dir, array $candidates): ?string
    {
        $files = scandir($dir);
        foreach ($candidates as $candidate) {
            foreach ($files as $f) {
                if (stripos($f, $candidate) !== false) {
                    return $dir . DIRECTORY_SEPARATOR . $f;
                }
            }
        }
        return null;
    }

    /**
     * 解析 CSV 文件为关联数组
     */
    protected function parseCsv(string $filePath): array
    {
        $rows = [];
        if (! file_exists($filePath)) return $rows;

        $handle = fopen($filePath, 'r');
        if ($handle === false) return $rows;

        // 去除 UTF-8 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = null;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if (! $header) {
                $header = array_map('trim', $data);
                continue;
            }

            if (count($data) === count($header)) {
                $rows[] = array_combine($header, $data);
            }
        }

        fclose($handle);
        return $rows;
    }
}
