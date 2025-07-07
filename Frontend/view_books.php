<?php
session_start();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

include '../backend/db.php';

// Configuration
$records_per_page = 10;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $records_per_page;

// Initialize variables
$search = '';
$category_filter = '';
$sort_by = 'created_at';
$sort_order = 'DESC';
$total_records = 0;

// Validate and sanitize inputs
if (isset($_GET['search'])) {
    $search = trim(filter_var($_GET['search'], FILTER_SANITIZE_STRING));
}

if (isset($_GET['category'])) {
    $category_filter = trim(filter_var($_GET['category'], FILTER_SANITIZE_STRING));
}

if (isset($_GET['sort'])) {
    $allowed_sorts = ['title', 'author', 'category', 'quantity', 'created_at', 'updated_at'];
    $sort_by = in_array($_GET['sort'], $allowed_sorts) ? $_GET['sort'] : 'created_at';
}

if (isset($_GET['order'])) {
    $sort_order = ($_GET['order'] === 'ASC') ? 'ASC' : 'DESC';
}

// Build query conditions
$conditions = ["is_deleted = FALSE"];
$params = [];
$param_types = "";

if (!empty($search)) {
    $conditions[] = "(title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $param_types .= "sss";
}

if (!empty($category_filter)) {
    $conditions[] = "category = ?";
    $params[] = $category_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM book WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Main query with pagination
$sql = "SELECT * FROM book WHERE $where_clause ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Database error: " . $conn->error);
    die("An error occurred while fetching books.");
}

// Bind parameters
$all_params = array_merge($params, [$records_per_page, $offset]);
$all_param_types = $param_types . "ii";
if (!empty($all_params)) {
    $stmt->bind_param($all_param_types, ...$all_params);
}

$stmt->execute();
$result = $stmt->get_result();

// Get categories for filter dropdown
$category_sql = "SELECT DISTINCT category FROM book WHERE is_deleted = FALSE ORDER BY category";
$category_result = $conn->query($category_sql);
$categories = [];
while ($row = $category_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

// Helper function for sorting arrows
function getSortIcon($column, $current_sort, $current_order) {
    if ($column === $current_sort) {
        return $current_order === 'ASC' ? '↑' : '↓';
    }
    return '';
}

// Helper function for sort URLs
function getSortUrl($column, $current_sort, $current_order) {
    $new_order = ($column === $current_sort && $current_order === 'ASC') ? 'DESC' : 'ASC';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $new_order;
    $params['page'] = 1; // Reset to first page when sorting
    return '?' . http_build_query($params);
}

// Helper function for pagination URLs
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Books - Library Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .table th {
            cursor: pointer;
            user-select: none;
        }
        .table th:hover {
            background-color: #495057;
        }
        .search-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .btn-export {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
        }
        .btn-export:hover {
            background: linear-gradient(135deg, #218838 0%, #1e7e34 100%);
            color: white;
        }
        .pagination-info {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-top: 20px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>
<body class="bg-light">
<div class="container-fluid p-4">
    <!-- Success/Error Messages -->
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>Book deleted successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>An error occurred. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="mb-0"><i class="fas fa-book me-2"></i>Book Records</h2>
            <p class="text-muted">Manage your library collection</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="add_book.php" class="btn btn-primary me-2">
                <i class="fas fa-plus me-1"></i>Add New Book
            </a>
            <button class="btn btn-export" onclick="exportBooks()">
                <i class="fas fa-download me-1"></i>Export
            </button>
        </div>
    </div>

    <!-- Stats Card -->
    <div class="stats-card">
        <div class="row">
            <div class="col-md-3">
                <h4><?= number_format($total_records) ?></h4>
                <p class="mb-0">Total Books</p>
            </div>
            <div class="col-md-3">
                <h4><?= number_format($current_page) ?></h4>
                <p class="mb-0">Current Page</p>
            </div>
            <div class="col-md-3">
                <h4><?= number_format($total_pages) ?></h4>
                <p class="mb-0">Total Pages</p>
            </div>
            <div class="col-md-3">
                <h4><?= number_format($records_per_page) ?></h4>
                <p class="mb-0">Records Per Page</p>
            </div>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="search-section">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Search Books</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Title, Author, or ISBN" 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category) ?>" 
                                <?= $category_filter === $category ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Sort By</label>
                <select name="sort" class="form-select">
                    <option value="created_at" <?= $sort_by === 'created_at' ? 'selected' : '' ?>>Date Added</option>
                    <option value="title" <?= $sort_by === 'title' ? 'selected' : '' ?>>Title</option>
                    <option value="author" <?= $sort_by === 'author' ? 'selected' : '' ?>>Author</option>
                    <option value="category" <?= $sort_by === 'category' ? 'selected' : '' ?>>Category</option>
                    <option value="quantity" <?= $sort_by === 'quantity' ? 'selected' : '' ?>>Quantity</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Order</label>
                <select name="order" class="form-select">
                    <option value="DESC" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>Descending</option>
                    <option value="ASC" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
        
        <?php if (!empty($search) || !empty($category_filter)): ?>
            <div class="mt-3">
                <a href="?" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i>Clear Filters
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Loading indicator -->
    <div class="loading" id="loading">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-2">Loading books...</p>
    </div>

    <!-- Books Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="booksTable">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th onclick="window.location.href='<?= getSortUrl('title', $sort_by, $sort_order) ?>'">
                                Title <?= getSortIcon('title', $sort_by, $sort_order) ?>
                            </th>
                            <th onclick="window.location.href='<?= getSortUrl('author', $sort_by, $sort_order) ?>'">
                                Author <?= getSortIcon('author', $sort_by, $sort_order) ?>
                            </th>
                            <th>ISBN</th>
                            <th onclick="window.location.href='<?= getSortUrl('category', $sort_by, $sort_order) ?>'">
                                Category <?= getSortIcon('category', $sort_by, $sort_order) ?>
                            </th>
                            <th onclick="window.location.href='<?= getSortUrl('quantity', $sort_by, $sort_order) ?>'">
                                Quantity <?= getSortIcon('quantity', $sort_by, $sort_order) ?>
                            </th>
                            <th onclick="window.location.href='<?= getSortUrl('created_at', $sort_by, $sort_order) ?>'">
                                Created <?= getSortIcon('created_at', $sort_by, $sort_order) ?>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?= $row['book_id'] ?></span></td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['title']) ?></strong>
                                        <?php if ($row['quantity'] <= 5): ?>
                                            <span class="badge bg-warning text-dark ms-2">Low Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['author']) ?></td>
                                    <td>
                                        <code><?= htmlspecialchars($row['isbn']) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= htmlspecialchars($row['category']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $row['quantity'] > 10 ? 'bg-success' : ($row['quantity'] > 5 ? 'bg-warning' : 'bg-danger') ?>">
                                            <?= $row['quantity'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= date('M j, Y', strtotime($row['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_book.php?id=<?= $row['book_id'] ?>" 
                                               class="btn btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_book.php?id=<?= $row['book_id'] ?>" 
                                               class="btn btn-sm btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn btn-sm btn-danger" 
                                                    onclick="deleteBook(<?= $row['book_id'] ?>, '<?= htmlspecialchars($row['title']) ?>')" 
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No books found</h5>
                                    <p class="text-muted">Try adjusting your search criteria or add some books to get started.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-info">
            <div class="col-md-6">
                <p class="text-muted mb-0">
                    Showing <?= min($offset + 1, $total_records) ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> results
                </p>
            </div>
            <div class="col-md-6">
                <nav aria-label="Book pagination">
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                        <?php if ($current_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= getPaginationUrl(1) ?>">First</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="<?= getPaginationUrl($current_page - 1) ?>">Previous</a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= getPaginationUrl($i) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?= getPaginationUrl($current_page + 1) ?>">Next</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="<?= getPaginationUrl($total_pages) ?>">Last</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this book?</p>
                <p><strong id="bookTitle"></strong></p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDelete" class="btn btn-danger">Delete Book</a>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Delete book function
function deleteBook(bookId, bookTitle) {
    document.getElementById('bookTitle').textContent = bookTitle;
    document.getElementById('confirmDelete').href = `../backend/delete_book.php?id=${bookId}`;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Export books function
function exportBooks() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'export_books.php?' + params.toString();
}

// Auto-submit form on sort/filter change
document.addEventListener('DOMContentLoaded', function() {
    const sortSelect = document.querySelector('select[name="sort"]');
    const orderSelect = document.querySelector('select[name="order"]');
    const categorySelect = document.querySelector('select[name="category"]');
    
    [sortSelect, orderSelect, categorySelect].forEach(select => {
        if (select) {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        }
    });
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});

// Show loading indicator on form submit
document.querySelector('form').addEventListener('submit', function() {
    document.getElementById('loading').style.display = 'block';
    document.getElementById('booksTable').style.opacity = '0.5';
});
</script>

</body>
</html>

<?php
// Cleanup
$stmt->close();
$count_stmt->close();
$conn->close();
?>