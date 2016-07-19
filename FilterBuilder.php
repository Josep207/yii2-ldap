<?php
/**
 * @link      https://github.com/chrmorandi/yii2-ldap for the canonical source repository
 * @package   yii2-ldap
 * @author    Christopher Mota <chrmorandi@gmail.com>
 * @license   MIT License - view the LICENSE file that was distributed with this source code.
 */

namespace chrmorandi\ldap;

use ArrayAccess;
use Traversable;
use yii\base\InvalidParamException;
use yii\base\Object;

/**
 * FilterBuilder builds a Filter for search in LDAP.
 *
 * FilterBuilder is also used by [[Query]] to build Filters.
 *
 * @author Christopher Mota <chrmorandi@gmail.com>
 * @since 1.0.0
 */
class FilterBuilder extends Object
{
    /**
     * @var string the separator between different fragments of a SQL statement.
     * Defaults to an empty space. This is mainly used by [[build()]] when generating a SQL statement.
     */
    public $separator = ' ';

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'NOT' => 'buildNotCondition',
        'AND' => 'buildAndCondition',
        'OR' => 'buildAndCondition',
        'IN' => 'buildInCondition',
        'NOT IN' => 'buildInCondition',
        'LIKE' => 'buildLikeCondition',
        'NOT LIKE' => 'buildLikeCondition',
        'OR LIKE' => 'buildLikeCondition',
        'OR NOT LIKE' => 'buildLikeCondition',
    ];
    
    /**
     * @var array map of operator for builder methods.
     */
    protected $operator = [
        'NOT' => '!',
        'AND' => '&',
        'OR' => '|',
        'LIKE' => '~=',
    ];

    /**
     * Parses the condition specification and generates the corresponding filters.
     * @param string|array $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @return string the generated
     */
    public function build($condition)
    {
       if (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($this->conditionBuilders[$operator])) {
                $method = $this->conditionBuilders[$operator];
            } else {
                $method = 'buildSimpleCondition';
            }
            array_shift($condition);
            return $this->$method($operator, $condition);
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            return $this->buildHashCondition($condition);
        }  
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @return string the generated 
     */
    public function buildHashCondition($condition)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if ($value === null) {
                $parts[] = "$column IS NULL";
            } else {
                $parts[] = "$column=$value";
            }
        }
        return count($parts) === 1 ? '('.$parts[0].')' : '$(' . implode(') (', $parts) . ')';
    }

    /**
     * Connects two or more Filters expressions with the `AND`(&) or `OR`(|) operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the Filter expressions to connect.
     * @return string the generated 
     */
    public function buildAndCondition($operator, $operands)
    {
        $parts = [];
        foreach ($operands as $key=>$operand) {
            if (is_array($operand)) {
                $operand = $this->build($operand);
            }
            if ($operand !== '' && !is_numeric($key)) {
                $parts[] = $key.'='.$operand;
            }elseif($operand !== ''){
                $other[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '('.$this->operator[$operator].'(' . implode(") (", $parts) . ')'.implode($other).' )';
        } else {
            return '';
        }
    }

    /**
     * Returns a query string for does not equal.
     * Produces: (!(field=value))
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the filter expressions to connect.
     * @return string the generated filter expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildNotCondition($operator, $operands)
    {
        if (count($operands) !== 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            $operand = $this->build($operand);
        }
        if ($operand === '') {
            return '';
        }

        return '('.$this->operator['NOT'].'('.key($operands) .'='.$operand.'))';
    }

    /**
     * Creates an SQL expressions with the `IN` operator.
     * @param string $operator the operator to use (e.g. `IN` or `NOT IN`)
     * @param array $operands the first operand is the column name. If it is an array
     * a composite IN condition will be generated.
     * The second operand is an array of values that column value should be among.
     * If it is an empty array the generated expression will be a `false` value if
     * operator is `IN` and empty if operator is `NOT IN`.
     * @return string the generated SQL expression
     * @throws Exception if wrong number of operands have been given.
     */
    public function buildInCondition($operator, $operands)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        if ($column === []) {
            return $operator === 'IN' ? '0=1' : '';
        }

        if ($values instanceof Query) {
            return $this->buildSubqueryInCondition($operator, $column, $values);
        }

        if ($column instanceof Traversable || count($column) > 1) {
            return $this->buildCompositeInCondition($operator, $column, $values);
        }

        if (is_array($column)) {
            $column = reset($column);
        }

        $sqlValues = [];
        foreach ($values as $i => $value) {
            if (is_array($value) || $value instanceof ArrayAccess) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            if ($value === null) {
                $sqlValues[$i] = 'NULL';
            } else {
                $sqlValues[$i] = $value;
            }
        }

        if (empty($sqlValues)) {
            return $operator === 'IN' ? '0=1' : '';
        }

        if (strpos($column, '(') === false) {
            $column = $this->db->quoteColumnName($column);
        }

        if (count($sqlValues) > 1) {
            return "$column $operator (" . implode(', ', $sqlValues) . ')';
        } else {
            $operator = $operator === 'IN' ? '=' : '<>';
            return $column . $operator . reset($sqlValues);
        }
    }

    /**
     * Builds SQL for IN condition
     *
     * @param string $operator
     * @param array $columns
     * @param Query $values
     * @return string SQL
     */
    protected function buildSubqueryInCondition($operator, $columns, $values)
    {
        list($sql, $params) = $this->build($values);
        if (is_array($columns)) {
            foreach ($columns as $i => $col) {
                if (strpos($col, '(') === false) {
                    $columns[$i] = $this->db->quoteColumnName($col);
                }
            }
            return '(' . implode(', ', $columns) . ") $operator ($sql)";
        } else {
            if (strpos($columns, '(') === false) {
                $columns = $this->db->quoteColumnName($columns);
            }
            return "$columns $operator ($sql)";
        }
    }

    /**
     * Builds SQL for IN condition
     *
     * @param string $operator
     * @param array|Traversable $columns
     * @param array $values
     * @return string SQL
     */
    protected function buildCompositeInCondition($operator, $columns, $values)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($columns as $column) {
                if (isset($value[$column])) {
                    $phName = self::PARAM_PREFIX . count($params);
                    $params[$phName] = $value[$column];
                    $vs[] = $phName;
                } else {
                    $vs[] = 'NULL';
                }
            }
            $vss[] = '(' . implode(', ', $vs) . ')';
        }

        if (empty($vss)) {
            return $operator === 'IN' ? '0=1' : '';
        };

        $sqlColumns = [];
        foreach ($columns as $i => $column) {
            $sqlColumns[] = strpos($column, '(') === false ? $this->db->quoteColumnName($column) : $column;
        }

        return '(' . implode(', ', $sqlColumns) . ") $operator (" . implode(', ', $vss) . ')';
    }

    /**
     * Creates an SQL expressions with the `LIKE` operator.
     * @param string $operator the operator to use (e.g. `LIKE`, `NOT LIKE`, `OR LIKE` or `OR NOT LIKE`)
     * @param array $operands an array of two or three operands
     *
     * - The first operand is the column name.
     * - The second operand is a single value or an array of values that column value
     *   should be compared with. If it is an empty array the generated expression will
     *   be a `false` value if operator is `LIKE` or `OR LIKE`, and empty if operator
     *   is `NOT LIKE` or `OR NOT LIKE`.
     * - An optional third operand can also be provided to specify how to escape special characters
     *   in the value(s). The operand should be an array of mappings from the special characters to their
     *   escaped counterparts. If this operand is not provided, a default escape mapping will be used.
     *   You may use `false` or an empty array to indicate the values are already escaped and no escape
     *   should be applied. Note that when using an escape mapping (or the third operand is not provided),
     *   the values will be automatically enclosed within a pair of percentage characters.
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildLikeCondition($operator, $operands)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        $escape = isset($operands[2]) ? $operands[2] : ['*' => '\*', '_' => '\_', '\\' => '\\\\'];
        unset($operands[2]);

        if (!preg_match('/^(AND |OR |)(((NOT |))I?LIKE)/', $operator, $matches)) {
            throw new InvalidParamException("Invalid operator '$operator'.");
        }
        $andor = (!empty($matches[1]) ? $matches[1] : 'AND ');
        $not = !empty($matches[3]);
        $operator = $matches[2];

        list($column, $values) = $operands;

        if (!is_array($values)) {
            $values = [$values];
        }

        if (empty($values)) {
            return $not ? '' : '0=1';
        }
        
        $not = ($operator == 'NOT LIKE') ? '(' . $this->operator['NOT'] : false;

        $parts = [];
        foreach ($values as $value) {
            $value = empty($escape) ? $value : strtr($value, $escape);
            $parts[] = $not . '(' . $column .'=*'.$value . '*)' . ($not? ')':'');
        }

        return '('.$this->operator[trim($andor)] . implode($parts). ')';
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array $operands contains two column names.
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildSimpleCondition($operator, $operands)
    {
        if (count($operands) !== 2) {
            throw new InvalidParamException("Operator '$operator' requires two operands.");
        }

        list($column, $value) = $operands;

        if ($value === null) {
            return "$column $operator NULL";
        } else {
            return "($column $operator $value)";
        }
    }
    
    /**
     * Escapes the given VALUES according to RFC 2254 so that they can be safely used in LDAP filters.
     *
     * Any control characters with an ACII code < 32 as well as the characters with special meaning in
     * LDAP filters "*", "(", ")", and "\" (the backslash) are converted into the representation of a
     * backslash followed by two hex digits representing the hexadecimal value of the character.
     * @see    Net_LDAP2_Util::escape_filter_value() from Benedikt Hallinger <beni@php.net>
     * @link   http://pear.php.net/package/Net_LDAP2
     * @author Benedikt Hallinger <beni@php.net>
     *
     * @param  string|array $values Array of values to escape
     * @return array Array $values, but escaped
     */
    public static function escapeValue($values = [])
    {
        if (!is_array($values)) {
            $values = [$values];
        }
        foreach ($values as $key => $val) {
            // Escaping of filter meta characters
            $val = str_replace(['\\', '*', '(', ')'], ['\5c', '\2a', '\28', '\29'], $val);
            // ASCII < 32 escaping
            $val = Converter::ascToHex32($val);
            if (null === $val) {
                $val = '\0'; // apply escaped "null" if string is empty
            }
            $values[$key] = $val;
        }
        return (count($values) == 1) ? $values[0] : $values;
    }

    /**
     * Undoes the conversion done by {@link escapeValue()}.
     *
     * Converts any sequences of a backslash followed by two hex digits into the corresponding character.
     * @see    Net_LDAP2_Util::escape_filter_value() from Benedikt Hallinger <beni@php.net>
     * @link   http://pear.php.net/package/Net_LDAP2
     * @author Benedikt Hallinger <beni@php.net>
     *
     * @param  string|array $values Array of values to escape
     * @return array Array $values, but unescaped
     */
    public static function unescapeValue($values = [])
    {
        if (!is_array($values)) {
            $values = [$values];
        }
        foreach ($values as $key => $value) {
            // Translate hex code into ascii
            $values[$key] = Converter::hex32ToAsc($value);
        }
        return (count($values) == 1) ? $values[0] : $values;
    }
    
}
