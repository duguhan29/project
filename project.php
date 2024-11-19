<?php
require_once __DIR__ . '/../php/db_connection.php';
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new DatabaseConnection();
$pdo = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $input = $_POST;

    if (!in_array($input['action'], ['add_project', 'modify_project_info'])) {
        if ($input['action'] == 'delete_project_product') {
            $stmt = $pdo->prepare('DELETE FROM project_product WHERE project_name = ? AND suning_code = ?');
            $stmt->execute([$input['project_name'], $input['suning_code']]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($input['action'] == 'bind_skus') {
            header('Content-Type: application/json; charset=UTF-8');
            try {
                $project_name = $input['project_name'];
                $suning_codes = array_map('trim', explode(PHP_EOL, $input['bulk_bind_codes']));

                $existing_suning_codes = [];
                $missing_suning_codes = [];
                $already_bound_suning_codes = [];
                $successful_bindings = 0;

                $stmt = $pdo->prepare('SELECT suning_code FROM suning_product WHERE suning_code = ?');
                $stmt_check = $pdo->prepare('SELECT COUNT(*) FROM project_product WHERE project_name = ? AND suning_code = ?');

                foreach ($suning_codes as $suning_code) {
                    $stmt->execute([$suning_code]);
                    if ($row = $stmt->fetch()) {
                        $stmt_check->execute([$project_name, $suning_code]);
                        $count = $stmt_check->fetchColumn();
                        if ($count == 0) {
                            $existing_suning_codes[] = $suning_code;
                        } else {
                            $already_bound_suning_codes[] = $suning_code;
                        }
                    } else {
                        $missing_suning_codes[] = $suning_code;
                    }
                }

                if (empty($existing_suning_codes)) {
                    $response = [
                        'status' => 'error',
                        'message' => '所有编码都已被绑定或不存在',
                        'missing_suning_codes' => $missing_suning_codes,
                        'already_bound_suning_codes' => $already_bound_suning_codes
                    ];
                    echo json_encode($response);
                    exit;
                }

                $stmt_insert = $pdo->prepare('INSERT INTO project_product (project_name, suning_code) VALUES (?, ?)');
                $failed_suning_codes = [];
                foreach ($existing_suning_codes as $suning_code) {
                    try {
                        $stmt_insert->execute([$project_name, $suning_code]);
                        $successful_bindings++;
                    } catch (PDOException $e) {
                        error_log("插入绑定记录失败: " . $e->getMessage());
                        $failed_suning_codes[] = [
                            'suning_code' => $suning_code,
                            'error' => "插入绑定记录到项目表时出错: " . $e->getMessage()
                        ];
                    }
                }

                $response = [
                    'status' => 'partial_success',
                    'successful_bindings' => $successful_bindings,
                    'failed_bindings' => count($failed_suning_codes),
                    'failed_suning_codes' => $failed_suning_codes,
                    'missing_suning_codes' => $missing_suning_codes,
                    'already_bound_suning_codes' => $already_bound_suning_codes
                ];

                if (count($failed_suning_codes) === 0
                    && count($missing_suning_codes) === 0
                    && count($already_bound_suning_codes) === 0) {
                    $response['status'] = 'success';
                }

                echo json_encode($response);
                exit;
            } catch (Exception $e) {
                error_log("绑定过程中出现异常: " . $e->getMessage());
                $response = [
                    'status' => 'error',
                    'message' => '绑定过程中出现异常: ' . $e->getMessage()
                ];
                echo json_encode($response);
                exit;
            }
        }
    } else {
        $project_name = $input['project_name'] ?? null;
        $project_status = $input['project_status'] ?? null;
        $sign_date = $input['sign_date'] ?? null;
        $end_date = $input['end_date'] ?? null;
        $platform_service_fee = $input['platform_service_fee'] ?? null;
        $account_period = $input['account_period'] ?? null;
        $expected_scale = $input['expected_scale'] ?? null;
        $client_manager = $input['client_manager'] ?? null;
        $client_manager_phone = $input['client_manager_phone'] ?? null;
        $authorization_requirements = $input['authorization_requirements'] ?? null;
        $performance_requirements = $input['performance_requirements'] ?? null;
        $remarks = $input['remarks'] ?? null;

        if ($input['action'] == 'add_project') {
            $stmt = $pdo->prepare(
                'INSERT INTO project_info (project_name, project_status, sign_date, end_date, platform_service_fee, account_period, expected_scale, 
                client_manager, client_manager_phone, authorization_requirements, performance_requirements, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $project_name, $project_status, $sign_date, $end_date, $platform_service_fee, $account_period, $expected_scale, 
                $client_manager, $client_manager_phone, $authorization_requirements, $performance_requirements, $remarks
            ]);
        } elseif ($input['action'] == 'modify_project_info') {
            $original_project_name = $input['original_project_name'];

            $stmt = $pdo->prepare(
                'UPDATE project_info SET project_name = ?, project_status = ?, sign_date = ?, end_date = ?, platform_service_fee = ?, account_period = ?, expected_scale = ?, 
                client_manager = ?, client_manager_phone = ?, authorization_requirements = ?, performance_requirements = ?, remarks = ? 
                WHERE project_name = ?'
            );
            $stmt->execute([
                $project_name, $project_status, $sign_date, $end_date, $platform_service_fee, $account_period, $expected_scale, 
                $client_manager, $client_manager_phone, $authorization_requirements, $performance_requirements, 
                $remarks, $original_project_name
            ]);

            if ($project_name !== $original_project_name) {
                $stmt = $pdo->prepare('UPDATE project_product SET project_name = ? WHERE project_name = ?');
                $stmt->execute([$project_name, $original_project_name]);
            }
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_GET['action'];

    if ($action == 'get_project_products') {
        $project = $_GET['project'];
        $stmt = $pdo->prepare('SELECT p.suning_code, p.brand, p.product_name, p.easypurchaseprice, p.exclusiveprice FROM project_product pp JOIN suning_product p ON pp.suning_code = p.suning_code WHERE pp.project_name = ?');
        $stmt->execute([$project]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($products);
        exit;
    }

    if ($action == 'search_suning_codes') {
        $project_name = $_GET['project'];
        $codes = explode(',', $_GET['codes']);
        $results = [];
        $stmt = $pdo->prepare('SELECT suning_code, product_name FROM suning_product WHERE suning_code = ?');
        $stmt_bind_check = $pdo->prepare('SELECT COUNT(*) FROM project_product WHERE project_name = ? AND suning_code = ?');

        foreach ($codes as $code) {
            $stmt->execute([$code]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $stmt_bind_check->execute([$project_name, $code]);
                $is_bound = $stmt_bind_check->fetchColumn() > 0;
                $results[] = ['code' => $code, 'exists' => true, 'product' => array_merge($product, ['project' => $is_bound])];
            } else {
                $results[] = ['code' => $code, 'exists' => false];
            }
        }

        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }
}

$searchConditions = [];
$params = [];
if (!empty($_GET['suning_code'])) {
    $searchConditions[] = 'suning_code LIKE ?';
    $params[] = '%' . $_GET['suning_code'] . '%';
}
if (!empty($_GET['brand'])) {
    $searchConditions[] = 'brand LIKE ?';
    $params[] = '%' . $_GET['brand'] . '%';
}
if (!empty($_GET['product_name'])) {
    $searchConditions[] = 'product_name LIKE ?';
    $params[] = '%' . $_GET['product_name'] . '%';
}

$whereClause = $searchConditions ? 'WHERE ' . implode(' AND ', $searchConditions) : '';
$stmt = $pdo->prepare('SELECT * FROM suning_product ' . $whereClause);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query('SELECT pi.*, COUNT(pp.suning_code) AS product_count FROM project_info pi LEFT JOIN project_product pp ON pi.project_name = pp.project_name GROUP BY pi.project_name');
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>维护项目信息</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css" rel="stylesheet">
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.9.3/umd/popper.min.js"></script>
    <script defer src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.min.js"></script>
    <style>
        body, table { font-size: 14px; }
        th, td { text-align: center; font-size: 12px; }
        .table th, .table td { vertical-align: middle; }
        .custom-container { max-width: 90%; margin-top: 30px; }
        .modal-body { padding: 20px; }
        .message { margin-top: 15px; color: red; }
        .btn-space { margin-right: 10px; }
        .mb-3 { margin-bottom: 1rem !important; }
        
        .form-label { font-weight: bold; }
        .form-control, .form-select { border: 1px solid #ddd; border-radius: 0; padding: 10px; }
        .form-group { margin-bottom: 20px; }
        .modal-footer { justify-content: flex-start; }
        .modal-header, .modal-footer {padding: 1rem 1.5rem;}
        .modal-dialog { max-width: 800px; }
        .modal-content {border-radius: 0;}
    </style>
</head>
<body>
<div class="container custom-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <button type="button" class="btn btn-primary btn-space" data-bs-toggle="modal" data-bs-target="#addProjectModal">新增项目</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-bordered">
            <thead>
            <tr>
                <th>项目名称</th>
                <th>项目状态</th>
                <th>签约时间</th>
                <th>截止时间</th>
                <th>平台服务费</th>
                <th>额外服务费</th>
                <th>账期(天)</th>
                <th>采购规模(元)</th>
                <th>项目负责人</th>
                <th>授权要求</th>
                <th>履约要求</th>
                <th>备注</th>
                <th>绑定产品数量</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <tr data-project="<?= htmlspecialchars($project['project_name']) ?>">
                    <td><?= htmlspecialchars($project['project_name']) ?></td>
                    <td><?= htmlspecialchars($project['project_status']) ?></td>
                    <td><?= htmlspecialchars($project['sign_date']) ?></td>
                    <td><?= htmlspecialchars($project['end_date']) ?></td>
                    <td><?= htmlspecialchars($project['client_manager']) ?></td>
                    <td><?= htmlspecialchars($project['platform_service_fee']) ?></td>
                    <td><?= htmlspecialchars($project['account_period']) ?></td>
                    <td><?= htmlspecialchars($project['expected_scale']) ?></td>
                    <td><?= htmlspecialchars($project['client_manager_phone']) ?></td>
                    <td><?= htmlspecialchars($project['authorization_requirements']) ?></td>
                    <td><?= htmlspecialchars($project['performance_requirements']) ?></td>
                    <td><?= htmlspecialchars($project['remarks']) ?></td>
                    <td><a href="#" class="view-products-link" data-bs-toggle="modal" data-bs-target="#viewProductsModal"
                           data-project="<?= htmlspecialchars($project['project_name']) ?>"><?= htmlspecialchars($project['product_count']) ?></a>
                    </td>
                    <td>
                        <button type="button" class="btn btn-info btn-sm modify-products-btn" data-bs-toggle="modal"
                                data-bs-target="#modifyProductsModal"
                                data-bs-project="<?= htmlspecialchars($project['project_name']) ?>">绑定SKU
                        </button>
                        <button type="button" class="btn btn-warning btn-sm modify-project-btn" data-bs-toggle="modal"
                                data-bs-target="#modifyProjectModal"
                                data-bs-project="<?= htmlspecialchars($project['project_name']) ?>"
                                data-bs-status="<?= htmlspecialchars($project['project_status']) ?>"
                                data-bs-sign-date="<?= htmlspecialchars($project['sign_date']) ?>"
                                data-bs-end-date="<?= htmlspecialchars($project['end_date']) ?>"
                                data-bs-manager="<?= htmlspecialchars($project['client_manager']) ?>"
                                data-bs-service-fee="<?= htmlspecialchars($project['platform_service_fee']) ?>"
                                data-bs-period="<?= htmlspecialchars($project['account_period']) ?>"
                                data-bs-expected-scale="<?= htmlspecialchars($project['expected_scale']) ?>"
                                data-bs-phone="<?= htmlspecialchars($project['client_manager_phone']) ?>"
                                data-bs-authorization-requirements="<?= htmlspecialchars($project['authorization_requirements']) ?>"
                                data-bs-performance-requirements="<?= htmlspecialchars($project['performance_requirements']) ?>"
                                data-bs-remarks="<?= htmlspecialchars($project['remarks']) ?>">修改
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 添加项目模态框 -->
<div class="modal fade" id="addProjectModal" tabindex="-1" aria-labelledby="addProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="addProjectForm" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'])?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProjectModalLabel">新增项目</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <!-- First Column -->
                            <div class="form-group">
                                <label for="project_name" class="form-label">项目名称:</label>
                                <input type="text" id="project_name" name="project_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="sign_date" class="form-label">签约时间:</label>
                                <input type="date" id="sign_date" name="sign_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="client_manager" class="form-label">平台服务费:</label>
                                <input type="text" id="client_manager" name="client_manager" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="platform_service_fee" class="form-label">平台额外服务费:</label>
                                <input type="text" id="platform_service_fee" name="platform_service_fee" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="authorization_requirements" class="form-label">授权要求:</label>
                                <textarea id="authorization_requirements" name="authorization_requirements" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="remarks" class="form-label">备注:</label>
                                <textarea id="remarks" name="remarks" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <!-- Second Column -->
                            <div class="form-group">
                                <label for="project_status" class="form-label">项目状态:</label>
                                <select id="project_status" name="project_status" class="form-control" required>
                                    <option value="待报价">待报价</option>
                                    <option value="进行中">进行中</option>
                                    <option value="已结束">已结束</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="end_date" class="form-label">截止时间:</label>
                                <input type="date" id="end_date" name="end_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="account_period" class="form-label">账期:</label>
                                <input type="text" id="account_period" name="account_period" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="expected_scale" class="form-label">采购规模:</label>
                                <input type="text" id="expected_scale" name="expected_scale" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="client_manager_phone" class="form-label">项目负责人:</label>
                                <input type="text" id="client_manager_phone" name="client_manager_phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="performance_requirements" class="form-label">履约要求:</label>
                                <textarea id="performance_requirements" name="performance_requirements" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="add_project">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modifyProjectModal" tabindex="-1" aria-labelledby="modifyProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="modifyProjectForm" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'])?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modifyProjectModalLabel">修改项目</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="original_project_name" name="original_project_name">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="modify_project_name" class="form-label">项目名称:</label>
                                <input type="text" id="modify_project_name" name="project_name" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="modify_sign_date" class="form-label">签约时间:</label>
                                <input type="date" id="modify_sign_date" name="sign_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_client_manager" class="form-label">平台服务费:</label>
                                <input type="text" id="modify_client_manager" name="client_manager" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_platform_service_fee" class="form-label">平台额外服务费:</label>
                                <input type="text" id="modify_platform_service_fee" name="platform_service_fee" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_authorization_requirements" class="form-label">授权要求:</label>
                                <textarea id="modify_authorization_requirements" name="authorization_requirements" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="modify_remarks" class="form-label">备注:</label>
                                <textarea id="modify_remarks" name="remarks" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="modify_project_status" class="form-label">项目状态:</label>
                                <select id="modify_project_status" name="project_status" class="form-control" required>
                                    <option value="待报价">待报价</option>
                                    <option value="进行中">进行中</option>
                                    <option value="已结束">已结束</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="modify_end_date" class="form-label">截止时间:</label>
                                <input type="date" id="modify_end_date" name="end_date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_account_period" class="form-label">账期:</label>
                                <input type="text" id="modify_account_period" name="account_period" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_expected_scale" class="form-label">采购规模:</label>
                                <input type="text" id="modify_expected_scale" name="expected_scale" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_client_manager_phone" class="form-label">项目负责人:</label>
                                <input type="text" id="modify_client_manager_phone" name="client_manager_phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="modify_performance_requirements" class="form-label">履约要求:</label>
                                <textarea id="modify_performance_requirements" name="performance_requirements" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="action" value="modify_project_info">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    <button type="submit" class="btn btn-primary">修改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 查看项目绑定的产品模态框 -->
<div class="modal fade" id="viewProductsModal" tabindex="-1" aria-labelledby="viewProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewProductsModalLabel">项目绑定的产品</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered">
                        <thead>
                        <tr>
                            <th>商品编码</th>
                            <th>品牌</th>
                            <th>名称</th>
                            <th>商品售价</th>
                            <th>专享价</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody id="projectProducts">
                        <!-- 动态填充的内容 -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- 管理项目绑定的产品模态框 -->
<div class="modal fade" id="modifyProductsModal" tabindex="-1" aria-labelledby="modifyProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="bindProductsForm" method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modifyProductsModalLabel">绑定产品</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                </div>
                <div class="modal-body">
                    <h6>输入编码并查询</h6>
                    <div class="form-group">
                        <label for="bulk_bind_codes" class="form-label">编码:</label>
                        <textarea id="bulk_bind_codes" name="bulk_bind_codes" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <button type="button" id="search_codes_button" class="btn btn-secondary mt-2">查询编码</button>
                    </div>
                    <div class="form-group mt-4">
                        <h6>查询结果:</h6>
                        <table id="search_results_table" class="table table-hover table-bordered">
                            <thead>
                            <tr>
                                <th>编码</th>
                                <th>产品名称</th>
                                <th>是否已绑定</th>
                            </tr>
                            </thead>
                            <tbody id="search_results">
                            <!-- 动态填充的内容 -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" id="bind_project_name" name="project_name">
                    <input type="hidden" name="action" value="bind_skus">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    <button type="submit" class="btn btn-primary">绑定</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 添加项目表单提交事件
    document.getElementById('addProjectForm').addEventListener('submit', function(event) {
        event.preventDefault();
        var formData = new FormData(this);
        fetch(this.getAttribute('action'), {
            method: 'POST',
            body: formData
        }).then(response => response.json())
          .then(res => {
            if (res.status === 'success') {
                location.reload();
            } else {
                alert('新增项目失败: ' + (res.error || '未知错误'));
            }
          }).catch(error => {
            console.error('新增项目请求出错:', error);
            alert('新增项目请求出错');
          });
    });

    // 修改项目表单提交事件
    document.getElementById('modifyProjectForm').addEventListener('submit', function(event) {
        event.preventDefault();
        var formData = new FormData(this);
        fetch(this.getAttribute('action'), {
            method: 'POST',
            body: formData
        }).then(response => response.json())
          .then(res => {
            if (res.status === 'success') {
                location.reload();
            } else {
            alert('修改项目失败: ' + (res.error || '未知错误'));
            }
          }).catch(error => {
            console.error('修改项目请求出错:', error);
            alert('修改项目请求出错');
          });
    });

    // 查看项目绑定的产品模态框显示事件
    var viewProductsModal = document.getElementById('viewProductsModal');
    viewProductsModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        var projectName = button.getAttribute('data-project');
        var projectProducts = document.getElementById('projectProducts');
        projectProducts.innerHTML = '';
        fetch('<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=get_project_products&project=' + encodeURIComponent(projectName))
            .then(response => response.json())
            .then(data => {
                if (data) {
                    data.forEach(function (product) {
                        var row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${product.suning_code}</td>
                            <td>${product.brand}</td>
                            <td>${product.product_name}</td>
                            <td>${product.easypurchaseprice}</td>
                            <td>${product.exclusiveprice}</td>
                            <td><button type="button" class="btn btn-danger btn-sm" onclick="deleteProduct('${projectName}', '${product.suning_code}')">删除</button></td>
                        `;
                        projectProducts.appendChild(row);
                    });
                }
            })
            .catch(error => {
                console.error('获取项目产品列表出错:', error);
            });
    });

    var modifyProjectModal = document.getElementById('modifyProjectModal');
modifyProjectModal.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    var projectName = button.getAttribute('data-bs-project');
    var projectStatus = button.getAttribute('data-bs-status');
    var signDate = button.getAttribute('data-bs-sign-date');
    var endDate = button.getAttribute('data-bs-end-date');
    var serviceFee = button.getAttribute('data-bs-service-fee');
    var accountPeriod = button.getAttribute('data-bs-period');
    var expectedScale = button.getAttribute('data-bs-expected-scale');
    var clientManager = button.getAttribute('data-bs-manager');
    var clientManagerPhone = button.getAttribute('data-bs-phone');
    var authorizationRequirements = button.getAttribute('data-bs-authorization-requirements');
    var performanceRequirements = button.getAttribute('data-bs-performance-requirements');
    var remarks = button.getAttribute('data-bs-remarks');
    var originalProjectName = document.getElementById('original_project_name');
    var modifyProjectName = document.getElementById('modify_project_name');
    var modifyProjectStatus = document.getElementById('modify_project_status');
    var modifySignDate = document.getElementById('modify_sign_date');
    var modifyEndDate = document.getElementById('modify_end_date');
    var modifyPlatformServiceFee = document.getElementById('modify_platform_service_fee');
    var modifyAccountPeriod = document.getElementById('modify_account_period');
    var modifyExpectedScale = document.getElementById('modify_expected_scale');
    var modifyClientManager = document.getElementById('modify_client_manager');
    var modifyClientManagerPhone = document.getElementById('modify_client_manager_phone');
    var modifyAuthorizationRequirements = document.getElementById('modify_authorization_requirements');
    var modifyPerformanceRequirements = document.getElementById('modify_performance_requirements');
    var modifyRemarks = document.getElementById('modify_remarks');

    originalProjectName.value = projectName;
    modifyProjectName.value = projectName;
    modifyProjectStatus.value = projectStatus;
    modifySignDate.value = signDate;
    modifyEndDate.value = endDate;
    modifyPlatformServiceFee.value = serviceFee;
    modifyAccountPeriod.value = accountPeriod;
    modifyExpectedScale.value = expectedScale;
    modifyClientManager.value = clientManager;
    modifyClientManagerPhone.value = clientManagerPhone;
    modifyAuthorizationRequirements.value = authorizationRequirements;
    modifyPerformanceRequirements.value = performanceRequirements;
    modifyRemarks.value = remarks;
});

    // 绑定产品模态框显示事件
    var modifyProductsModal = document.getElementById('modifyProductsModal');
    modifyProductsModal.addEventListener('show.bs.modal', function(event) {
        var button = event.relatedTarget;
        var projectName = button.getAttribute('data-bs-project');
        var bindProjectName = document.getElementById('bind_project_name');
        bindProjectName.value = projectName;
        document.getElementById('search_results').innerHTML = '';
    });

    // 查询编码按钮点击事件
    document.getElementById('search_codes_button').addEventListener('click', function() {
        var bulkBindCodes = document.getElementById('bulk_bind_codes').value.trim();
        if (bulkBindCodes) {
            var codes = bulkBindCodes.split(/\r?\n/).map(code => code.trim());
            var tbody = document.getElementById('search_results');
            tbody.innerHTML = '';
            var params = new URLSearchParams({
                action: 'search_suning_codes',
                project: document.getElementById('bind_project_name').value,
                codes: codes.join(',')
            });

            fetch('<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.results) {
                        data.results.forEach(function(result) {
                            var row = document.createElement('tr');
                            row.innerHTML = `
                                <td>${result.code}</td>
                                <td>${result.exists ? result.product.product_name : 'N/A'}</td>
                                <td>${result.exists ? (result.product.project ? '已绑定' : '未绑定') : '编码不存在'}</td>
                            `;
                            tbody.appendChild(row);
                        });
                    } else {
                        console.error('查询编码失败:', data);
                    }
                })
                .catch(error => {
                    console.error('查询编码出错:', error);
                });
        }
    });

    // 绑定产品表单提交事件
    var bindProductsForm = document.getElementById('bindProductsForm');
    bindProductsForm.addEventListener('submit', function(event) {
        event.preventDefault();
        var formData = new FormData(bindProductsForm);
        var searchParams = new URLSearchParams();
        for (const pair of formData) {
            searchParams.append(pair[0], pair[1]);
        }
        var tbody = document.getElementById('search_results');
        tbody.innerHTML = '';

        fetch('<?= htmlspecialchars($_SERVER['PHP_SELF'])?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: searchParams.toString()
        }).then(response => response.json())
          .then(res => {
            tbody.innerHTML = '';  // 清除之前的结果

            if (res.status === 'success' || res.status === 'partial_success') {
                if (res.successful_bindings > 0) {
                    var successRow = document.createElement('tr');
                    successRow.innerHTML = `<td colspan="3" class="text-success">绑定成功 ${res.successful_bindings} 个编码</td>`;
                    tbody.appendChild(successRow);

                    var projectRow = document.querySelector(`tr[data-project="${document.getElementById('bind_project_name').value}"]`);
                    if (projectRow) {
                        var productCountElement = projectRow.querySelector('.view-products-link');
                        if (productCountElement) {
                            var newProductCount = parseInt(productCountElement.textContent, 10) + res.successful_bindings;
                            productCountElement.textContent = newProductCount;
                        }
                    }
                }

                if (res.failed_bindings > 0) {
                    res.failed_suning_codes.forEach(function(item) {
                        var errorRow = document.createElement('tr');
                        errorRow.innerHTML = `<td colspan="3" class="text-danger">编码 ${item.suning_code} 绑定失败，原因: ${item.error}</td>`;
                        tbody.appendChild(errorRow);
                    });
                }

                res.already_bound_suning_codes.forEach(function(suning_code) {
                    var errorRow = document.createElement('tr');
                    errorRow.innerHTML = `<td colspan="3" class="text-danger">编码 ${suning_code} 已被绑定</td>`;
                    tbody.appendChild(errorRow);
                });

                res.missing_suning_codes.forEach(function(suning_code) {
                    var errorRow = document.createElement('tr');
                    errorRow.innerHTML = `<td colspan="3" class="text-danger">编码 ${suning_code} 不存在</td>`;
                    tbody.appendChild(errorRow);
                });

            } else {
                var errorMessage = res.message || '未知错误';
                var errorRow = document.createElement('tr');
                errorRow.innerHTML = `<td colspan="3" class="text-danger">绑定过程中出错：${errorMessage}</td>`;
                tbody.appendChild(errorRow);
            }
        }).catch(error => {
            console.error('绑定请求出错:', error);
        });
    });

    // 删除产品绑定
    function deleteProduct(projectName, suningCode) {
        if (confirm('确定要删除这个绑定的产品吗？')) {
            var formData = new FormData();
            formData.append("action", "delete_project_product");
            formData.append("project_name", projectName);
            formData.append("suning_code", suningCode);

            fetch('<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(formData).toString()
            })
            .then(response => response.json())
            .then(res => {
                if (res.status === 'success') {
                    var projectRow = document.querySelector(`tr[data-project="${projectName}"]`);
                    if (projectRow) {
                        var productCountElement = projectRow.querySelector('.view-products-link');
                        if (productCountElement) {
                            var newProductCount = parseInt(productCountElement.textContent, 10) - 1;
                            productCountElement.textContent = newProductCount;
                        }
                    }
                    var projectProducts = document.getElementById('projectProducts');
                    projectProducts.innerHTML = '';
                    fetch('<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=get_project_products&project=' + encodeURIComponent(projectName))
                        .then(response => response.json())
                        .then(data => {
                            if (data) {
                                data.forEach(function(product) {
                                    var row = document.createElement('tr');
                                    row.innerHTML = `
                                        <td>${product.suning_code}</td>
                                        <td>${product.brand}</td>
                                        <td>${product.product_name}</td>
                                        <td>${product.easypurchaseprice}</td>
                                        <td>${product.exclusiveprice}</td>
                                        <td><button type="button" class="btn btn-danger btn-sm" onclick="deleteProduct('${projectName}', '${product.suning_code}')">删除</button></td>
                                    `;
                                    projectProducts.appendChild(row);
                                });
                            }
                        });
                } else {
                    console.error('删除失败:', res.error);
                }
            })
            .catch(error => {
                console.error('请求出错:', error);
            });
        }
    }
</script>
</body>
</html>
