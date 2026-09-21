<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Session\Session;
use Config\ShopLockdown;

/**
 * Employee class
 *
 * @property Session session
 */
class Employee extends Person
{
    public Session $session;
    private ?string $save_failure_reason = null;
    protected $table                     = 'Employees';
    protected $primaryKey                = 'person_id';
    protected $useAutoIncrement          = false;
    protected $useSoftDeletes            = false;
    protected $allowedFields             = [
        'username',
        'password',
        'deleted',
        'hashversion',
        'language',
        'language_code',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->session = session();
    }

    /**
     * Determines if a given person_id is an employee
     */
    public function exists(int $person_id): bool
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);

        return $builder->get()->getNumRows() == 1;    // TODO: ===
    }

    public function username_exists(int $employee_id, string $username): bool
    {
        $builder = $this->db->table('employees');
        $builder->where('employees.username', $username);
        $builder->where('employees.person_id <>', $employee_id);

        return $builder->get()->getNumRows() == 1;    // TODO: ===
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * Returns all the employees
     */
    public function get_all(int $limit = 10000, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->orderBy('last_name', 'asc');
        $builder->limit($limit);
        $builder->offset($offset);

        return $builder->get();
    }

    /**
     * Gets information about a particular employee
     */
    public function get_info(int $person_id): object
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);
        $query = $builder->get();

        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        }

        // Get empty base parent object, as $employee_id is NOT an employee
        $person_obj = parent::get_info(NEW_ITEM);

        // Get all the fields from employee table
        // Append those fields to base parent object, we have a complete empty object
        foreach ($this->db->getFieldNames('employees') as $field) {
            $person_obj->{$field} = null;
        }

        return $person_obj;
    }

    /**
     * Gets information about multiple employees
     */
    public function get_multiple_info(array $person_ids): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->whereIn('employees.person_id', $person_ids);
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Inserts or updates an employee while applying the permission ceiling.
     *
     * Existing administrators receive the selected grants posted by the
     * employee form, except for removed modules. New and existing
     * non-administrators cannot receive administrative grants. A new employee
     * with no posted grants receives the standard cashier grants.
     *
     * @param array<string, mixed>             $person_data   Person fields to save.
     * @param array<string, mixed>             $employee_data Employee fields to save.
     * @param array<int, array<string, mixed>> $grants_data   Requested grants.
     * @param int                              $employee_id   Employee identifier to update, or NEW_ENTRY.
     */
    public function save_employee(array &$person_data, array &$employee_data, array &$grants_data, int $employee_id = NEW_ENTRY): bool
    {
        $this->save_failure_reason = null;

        if ($employee_id !== NEW_ENTRY && ! $this->exists($employee_id)) {
            return false;
        }

        $current_is_admin = $employee_id !== NEW_ENTRY
            && $this->has_administrator_capability($employee_id);
        $stock_admin_fallback = $employee_id !== NEW_ENTRY
            && ! $current_is_admin
            && ! $this->has_any_grant($employee_id)
            && $this->is_stock_admin($employee_id);
        $requested_is_admin = $this->requested_administrator_capability($grants_data);

        if (($current_is_admin || $stock_admin_fallback)
            && ! $requested_is_admin
            && ! $this->has_other_active_holder('config', $employee_id)) {
            $this->save_failure_reason = 'last_administrator';

            return false;
        }

        $is_admin = $current_is_admin && $requested_is_admin;

        if ($employee_id === NEW_ENTRY && $grants_data === []) {
            $grants_data = $this->standard_cashier_grants();
        } else {
            $grants_data = $this->prepare_grants($grants_data, $is_admin);
        }

        $success = false;

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        if (parent::save_value($person_data, $employee_id)) {
            $builder = $this->db->table('employees');
            if ($employee_id == NEW_ENTRY) {
                $employee_data['person_id'] = $employee_id = $person_data['person_id'];
                $success                    = $builder->insert($employee_data);
            } else {
                $builder->where('person_id', $employee_id);
                $success = $builder->update($employee_data);
            }

            // We have either inserted or updated a new employee, now lets set permissions.
            if ($success) {
                // First lets clear out any grants the employee currently has.
                $builder = $this->db->table('grants');
                $success = $builder->delete(['person_id' => $employee_id]);

                // Now insert the new grants
                if ($success) {
                    foreach ($grants_data as $grant) {
                        $data = [
                            'permission_id' => $grant['permission_id'],
                            'person_id'     => $employee_id,
                            'menu_group'    => $grant['menu_group'],
                        ];

                        $builder = $this->db->table('grants');
                        $success = $builder->insert($data);
                    }
                }
            }
        }

        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Returns the reason for the last refused employee save, if one exists.
     */
    public function get_save_failure_reason(): ?string
    {
        return $this->save_failure_reason;
    }

    /**
     * Checks whether an active employee has the config capability used for administration.
     */
    private function has_administrator_capability(int $employee_id): bool
    {
        return $this->has_capability('config', $employee_id);
    }

    /**
     * Checks whether an active employee has a named capability grant.
     */
    private function has_capability(string $permission_id, int $employee_id): bool
    {
        return $this->db->table('employees AS employees')
            ->join('grants AS grants', 'grants.person_id = employees.person_id')
            ->where('employees.person_id', $employee_id)
            ->where('employees.deleted', 0)
            ->where('grants.permission_id', $permission_id)
            ->countAllResults() > 0;
    }

    /**
     * Checks whether an employee has any grant row to inspect.
     */
    private function has_any_grant(int $employee_id): bool
    {
        return $this->db->table('grants')
            ->where('person_id', $employee_id)
            ->countAllResults() > 0;
    }

    /**
     * Checks whether an existing employee is the stock admin account.
     */
    private function is_stock_admin(int $employee_id): bool
    {
        return ($this->get_info($employee_id)->username ?? null) === 'admin';
    }

    /**
     * Checks whether requested grants include the administrator capability.
     *
     * @param array<int, array<string, mixed>> $grants_data Requested grant rows.
     */
    private function requested_administrator_capability(array $grants_data): bool
    {
        return $this->requested_grant($grants_data, 'config');
    }

    /**
     * Checks whether requested grants include a permission.
     *
     * @param array<int, array<string, mixed>> $grants_data Requested grant rows.
     */
    private function requested_grant(array $grants_data, string $permission_id): bool
    {
        foreach ($grants_data as $grant) {
            if (($grant['permission_id'] ?? null) === $permission_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether another active employee holds a protected capability.
     */
    private function has_other_active_holder(string $permission_id, int $employee_id): bool
    {
        return $this->db->table('employees AS employees')
            ->join('grants AS grants', 'grants.person_id = employees.person_id')
            ->where('employees.deleted', 0)
            ->where('grants.permission_id', $permission_id)
            ->where('grants.person_id !=', $employee_id)
            ->countAllResults() > 0;
    }

    /**
     * Checks whether deleting the given employees would remove every active administrator.
     *
     * @param array<int, int> $person_ids Employee identifiers to delete.
     */
    private function deleting_last_administrator(array $person_ids): bool
    {
        $active_administrators = $this->db->table('employees AS employees')
            ->join('grants AS grants', 'grants.person_id = employees.person_id')
            ->where('employees.deleted', 0)
            ->where('grants.permission_id', 'config')
            ->countAllResults();

        if ($active_administrators === 0) {
            return false;
        }

        $administrators_to_delete = $this->db->table('employees AS employees')
            ->join('grants AS grants', 'grants.person_id = employees.person_id')
            ->where('employees.deleted', 0)
            ->where('grants.permission_id', 'config')
            ->whereIn('employees.person_id', $person_ids)
            ->countAllResults();

        return $administrators_to_delete === $active_administrators;
    }

    /**
     * Builds the standard cashier grants, including active stock-location grants.
     *
     * @return list<array<string, string>>
     */
    private function standard_cashier_grants(): array
    {
        $permission_ids = ShopLockdown::STANDARD_CASHIER_GRANTS;
        $stock_rows     = $this->db->table('stock_locations')
            ->select('location_name')
            ->where('deleted', 0)
            ->get()
            ->getResultArray();

        foreach ($stock_rows as $stock_row) {
            $location_name    = str_replace(' ', '_', $stock_row['location_name']);
            $permission_ids[] = 'items_' . $location_name;
            $permission_ids[] = 'sales_' . $location_name;
        }

        $grants_data = [];

        foreach (array_unique($permission_ids) as $permission_id) {
            $grants_data[] = [
                'permission_id' => $permission_id,
                'menu_group'    => ShopLockdown::menu_group($permission_id),
            ];
        }

        return $this->prepare_grants($grants_data, false);
    }

    /**
     * Filters requested grants through the permission ceiling and assigns the
     * fixed menu group for each module-level permission.
     *
     * @param array<int, array<string, mixed>> $grants_data Requested grant rows.
     *
     * @return list<array<string, string>>
     */
    private function prepare_grants(array $grants_data, bool $is_admin): array
    {
        $permission_rows = $this->db->table('permissions')
            ->select('permission_id, module_id')
            ->get()
            ->getResultArray();
        $permissions = [];

        foreach ($permission_rows as $permission_row) {
            $permissions[$permission_row['permission_id']] = $permission_row['module_id'];
        }

        $prepared = [];
        $seen     = [];

        foreach ($grants_data as $grant) {
            $permission_id = (string) ($grant['permission_id'] ?? '');
            if ($permission_id === '' || ! isset($permissions[$permission_id]) || isset($seen[$permission_id])) {
                continue;
            }

            $module_id = $permissions[$permission_id];
            if (in_array($module_id, ShopLockdown::REMOVED_MODULES, true) || (! $is_admin && in_array($permission_id, ShopLockdown::ADMINISTRATIVE_GRANTS, true))) {
                continue;
            }

            $seen[$permission_id] = true;
            $menu_group           = $permission_id === $module_id
                ? ShopLockdown::menu_group($permission_id)
                : '--';

            $prepared[] = [
                'permission_id' => $permission_id,
                'menu_group'    => $menu_group,
            ];
        }

        return $prepared;
    }

    /**
     * Soft-deletes one employee unless that employee is the last active administrator.
     *
     * @param mixed|null $employee_id Employee identifier to delete.
     */
    public function delete($employee_id = null, bool $purge = false): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if ($employee_id == $this->get_logged_in_employee_info()->person_id) {
            return false;
        }

        if ($this->deleting_last_administrator([(int) $employee_id])) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        // Delete permissions
        $builder = $this->db->table('grants');

        if ($builder->delete(['person_id' => $employee_id])) {
            $builder = $this->db->table('employees');
            $builder->where('person_id', $employee_id);
            $success = $builder->update(['deleted' => 1]);
        }

        $this->db->transComplete();

        return $success;
    }

    /**
     * Soft-deletes a list of employees unless it removes every active administrator.
     *
     * @param array<int, int> $person_ids Employee identifiers to delete.
     */
    public function delete_list(array $person_ids): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if (in_array($this->get_logged_in_employee_info()->person_id, $person_ids)) {
            return false;
        }

        if ($this->deleting_last_administrator($person_ids)) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        $builder = $this->db->table('grants');
        $builder->whereIn('person_id', $person_ids);
        // Delete permissions
        if ($builder->delete()) {
            // Delete from employee table
            $builder = $this->db->table('employees');
            $builder->whereIn('person_id', $person_ids);
            $success = $builder->update(['deleted' => 1]);
        }

        $this->db->transComplete();
        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Get search suggestions to find employees
     */
    public function get_search_suggestions(string $search, int $limit = 25, bool $unique = false): array
    {
        $suggestions = [];

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->orderBy('last_name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->first_name . ' ' . $row->last_name];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('email', $search);
        $builder->orderBy('email', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->email];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('username', $search);
        $builder->orderBy('username', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->username];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('phone_number', $search);
        $builder->orderBy('phone_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->phone_number];
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search): int
    {
        return $this->search($search, 0, 0, 'last_name', 'asc', true);
    }

    /**
     * Performs a search on employees
     */
    public function search(string $search, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'last_name', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) {
            $rows = 0;
        }
        if ($limit_from == null) {
            $limit_from = 0;
        }
        if ($sort == null) {
            $sort = 'last_name';
        }
        if ($order == null) {
            $order = 'asc';
        }
        if ($count_only == null) {
            $count_only = false;
        }

        $builder = $this->db->table('employees AS employees');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(employees.person_id) as count');
        }

        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('email', $search);
        $builder->orLike('phone_number', $search);
        $builder->orLike('username', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();
        $builder->where('deleted', 0);

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function login(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query   = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() === 1) {
            $row = $query->getRow();

            // Compare passwords depending on the hash version
            if ($row->hash_version === '1' && $row->password === md5($password)) {
                $builder->where('person_id', $row->person_id);
                $this->session->set('person_id', $row->person_id);
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                return $builder->update(['hash_version' => 2, 'password' => $password_hash]);
            }
            if ($row->hash_version === '2' && password_verify($password, $row->password)) {
                $this->session->set('person_id', $row->person_id);

                return true;
            }
        }

        return false;
    }

    /**
     * Logs out a user by destroying all session data and redirect to log in
     */
    public function logout(): void
    {
        session()->destroy();
    }

    /**
     * Determines if an employee is logged in
     */
    public function is_logged_in(): bool
    {
        return $this->session->get('person_id') != false;
    }

    /**
     * Gets information about the currently logged in employee.
     */
    public function get_logged_in_employee_info()
    {
        if ($this->is_logged_in()) {
            return $this->get_info($this->session->get('person_id'));
        }

        return false;
    }

    /**
     * Determines whether the employee has access to at least one submodule
     */
    public function has_module_grant(string $permission_id, int $person_id): bool
    {
        $builder = $this->db->table('grants');
        $builder->like('permission_id', $permission_id, 'after');
        $builder->where('person_id', $person_id);
        $result_count = $builder->get()->getNumRows();

        if ($result_count != 1) {
            return $result_count != 0;
        }

        return $this->has_subpermissions($permission_id);
    }

    /**
     * Checks permissions
     */
    public function has_subpermissions(string $permission_id): bool
    {
        $builder = $this->db->table('permissions');
        $builder->like('permission_id', $permission_id . '_', 'after');

        return $builder->get()->getNumRows() == 0;    // TODO: ===
    }

    /**
     * Determines whether the employee specified employee has access the specific module.
     */
    public function has_grant(?string $permission_id, ?int $person_id): bool
    {
        // If no module_id is null, allow access
        if ($permission_id == null) {
            return true;
        }
        if ($person_id == null) {
            return false;
        }

        $builder = $this->db->table('grants');
        $query   = $builder->getWhere(['person_id' => $person_id, 'permission_id' => $permission_id], 1);

        return $query->getNumRows() == 1;    // TODO: ===
    }

    /**
     * Returns the fixed menu group assigned to a module by the shop policy.
     * The employee ID is retained for the upstream method signature but does
     * not affect placement.
     *
     * @param string   $permission_id Module or permission ID.
     * @param int|null $person_id     Unused employee ID retained for callers.
     */
    public function get_menu_group(string $permission_id, ?int $person_id): string
    {
        return ShopLockdown::menu_group($permission_id);
    }

    /**
     * Gets employee permission grants
     */
    public function get_employee_grants(int $person_id): array
    {
        $builder = $this->db->table('grants');
        $builder->where('person_id', $person_id);

        return $builder->get()->getResultArray();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function check_password(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query   = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() == 1) {    // TODO: ===
            $row = $query->getRow();

            if (password_verify($password, $row->password)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change password for the employee
     *
     * @param mixed $employee_id
     */
    public function change_password(array $employee_data, $employee_id = false): bool
    {
        $success = false;

        if (ENVIRONMENT != 'testing') {
            $this->db->transStart();

            $builder = $this->db->table('employees');
            $builder->where('person_id', $employee_id);
            $success = $builder->update($employee_data);

            $this->db->transComplete();

            $success &= $this->db->transStatus();
        }

        return $success;
    }
}
