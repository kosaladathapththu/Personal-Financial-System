SELECT sys_context('USERENV','SESSION_USER') AS who_am_i,
       sys_context('USERENV','CON_NAME')     AS con_name
FROM dual;


-- USERS_CLOUD
CREATE TABLE USERS_CLOUD (
  server_user_id   NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email            VARCHAR2(255) UNIQUE NOT NULL,
  password_hash    VARCHAR2(255) NOT NULL,
  full_name        VARCHAR2(255) NOT NULL,
  last_login_at    TIMESTAMP,
  created_at       TIMESTAMP DEFAULT SYSTIMESTAMP,
  updated_at       TIMESTAMP DEFAULT SYSTIMESTAMP
);

-- ACCOUNTS_CLOUD
CREATE TABLE ACCOUNTS_CLOUD (
  server_account_id NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_server_id    NUMBER NOT NULL,
  account_name      VARCHAR2(100) NOT NULL,
  account_type      VARCHAR2(20)  CHECK (account_type IN ('CASH','BANK','CARD','MOBILE')),
  currency_code     VARCHAR2(10)  DEFAULT 'LKR' NOT NULL,
  opening_balance   NUMBER(15,2)  DEFAULT 0 NOT NULL,
  is_active         NUMBER(1)     DEFAULT 1 NOT NULL,
  created_at        TIMESTAMP     NOT NULL,
  updated_at        TIMESTAMP     NOT NULL,
  CONSTRAINT fk_acc_user FOREIGN KEY (user_server_id)
    REFERENCES USERS_CLOUD(server_user_id)
);

-- Helpful index
CREATE INDEX IDX_ACCOUNTS_USER ON ACCOUNTS_CLOUD(user_server_id);

-- CATEGORIES_CLOUD
CREATE TABLE CATEGORIES_CLOUD (
  server_category_id NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_server_id     NUMBER NOT NULL,
  parent_server_id   NUMBER NULL,
  category_name      VARCHAR2(100) NOT NULL,
  category_type      VARCHAR2(20)  CHECK (category_type IN ('INCOME','EXPENSE')),
  created_at         TIMESTAMP NOT NULL,
  updated_at         TIMESTAMP NOT NULL,
  CONSTRAINT fk_cat_user   FOREIGN KEY (user_server_id)
    REFERENCES USERS_CLOUD(server_user_id),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_server_id)
    REFERENCES CATEGORIES_CLOUD(server_category_id)
);

CREATE INDEX IDX_CATEGORIES_USER ON CATEGORIES_CLOUD(user_server_id);

-- TRANSACTIONS_CLOUD
CREATE TABLE TRANSACTIONS_CLOUD (
  server_txn_id      NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  client_txn_uuid    VARCHAR2(36) UNIQUE NOT NULL,
  user_server_id     NUMBER NOT NULL,
  account_server_id  NUMBER NOT NULL,
  category_server_id NUMBER NOT NULL,
  txn_type           VARCHAR2(10) CHECK (txn_type IN ('INCOME','EXPENSE','TRANSFER')),
  amount             NUMBER(15,2) NOT NULL,
  txn_date           DATE NOT NULL,
  note               VARCHAR2(255),
  created_at         TIMESTAMP NOT NULL,
  updated_at         TIMESTAMP NOT NULL,
  CONSTRAINT fk_txn_user  FOREIGN KEY (user_server_id)
    REFERENCES USERS_CLOUD(server_user_id),
  CONSTRAINT fk_txn_acc   FOREIGN KEY (account_server_id)
    REFERENCES ACCOUNTS_CLOUD(server_account_id),
  CONSTRAINT fk_txn_cat   FOREIGN KEY (category_server_id)
    REFERENCES CATEGORIES_CLOUD(server_category_id)
);

CREATE INDEX IDX_TRANSACTIONS_USER ON TRANSACTIONS_CLOUD(user_server_id);
CREATE INDEX IDX_TRANSACTIONS_DATE ON TRANSACTIONS_CLOUD(txn_date);




-------------------------------------------------------------------------------------------------------

-- Should show 4 rows (the tables you created)
SELECT table_name
FROM user_tables
WHERE table_name IN ('USERS_CLOUD','ACCOUNTS_CLOUD','CATEGORIES_CLOUD','TRANSACTIONS_CLOUD')
ORDER BY table_name;

-- Verify you are really inside KOSALA on XEPDB1
SELECT
  sys_context('USERENV','DB_NAME')  AS db_name,
  sys_context('USERENV','CON_NAME') AS con_name,
  sys_context('USERENV','SESSION_USER') AS session_user
FROM dual;



---------------------------------------------------------------------------------------------------------


-- accounts available for this user
SELECT server_account_id, account_name, account_type
FROM ACCOUNTS_CLOUD
WHERE user_server_id = 1
ORDER BY server_account_id;

-- categories available for this user
SELECT server_category_id, parent_server_id, category_name, category_type
FROM CATEGORIES_CLOUD
WHERE user_server_id = 1
ORDER BY server_category_id;

-------------------------------------------------------------------------------------------------------------


-- ========== MV 1: Account summary (all-time per account) ==========
-- Shows: account_name, opening, total_income, total_expense, balance
-- Note: no date filter inside the MV; you can filter by user in SELECTs.
DROP MATERIALIZED VIEW MV_ACCOUNT_SUMMARY PURGE;

CREATE MATERIALIZED VIEW MV_ACCOUNT_SUMMARY
BUILD IMMEDIATE
REFRESH COMPLETE ON DEMAND
AS
SELECT
  a.user_server_id,
  a.server_account_id,
  a.account_name,
  NVL(a.opening_balance,0)                                         AS opening_balance,
  NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount END), 0) AS total_income,
  NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount END), 0) AS total_expense,
  NVL(a.opening_balance,0)
    + NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN t.amount END), 0)
    - NVL(SUM(CASE WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN t.amount END), 0)     AS balance
FROM ACCOUNTS_CLOUD a
LEFT JOIN TRANSACTIONS_CLOUD t
  ON t.account_server_id = a.server_account_id
GROUP BY
  a.user_server_id,
  a.server_account_id,
  a.account_name,
  a.opening_balance;

-- (Optional) create an index to speed filtering by user
CREATE INDEX IDX_MV_ACC_SUM_USER ON MV_ACCOUNT_SUMMARY(user_server_id);


-- ========== MV 2: Transactions (minimal) with running account balance ==========
-- Shows: date, type, amount, account, category, note, running_balance
DROP MATERIALIZED VIEW KOSALA.MV_TXN_MINIMAL PURGE;

CREATE MATERIALIZED VIEW MV_TXN_MINIMAL1
BUILD IMMEDIATE
REFRESH COMPLETE ON DEMAND
AS
WITH base AS (
  SELECT
    t.server_txn_id,
    t.user_server_id,
    t.txn_date,
    TRIM(UPPER(t.txn_type))                                              AS txn_type,
    t.amount,
    t.note,
    a.server_account_id,
    a.account_name,
    NVL(a.opening_balance,0)                                             AS opening_balance,
    NVL(c.category_name,'Uncategorized')                                  AS category_name,
    CASE
      WHEN TRIM(UPPER(t.txn_type))='INCOME'  THEN  t.amount
      WHEN TRIM(UPPER(t.txn_type))='EXPENSE' THEN -t.amount
      ELSE 0
    END                                                                  AS signed_amount
  FROM TRANSACTIONS_CLOUD t
  JOIN ACCOUNTS_CLOUD    a ON a.server_account_id   = t.account_server_id
  LEFT JOIN CATEGORIES_CLOUD c ON c.server_category_id = t.category_server_id
)
SELECT
  b.server_txn_id,
  b.user_server_id,
  b.txn_date,
  b.txn_type,
  b.amount,
  b.note,
  b.server_account_id,
  b.account_name,
  b.category_name,
  /* opening balance + cumulative sum of signed amounts per account */
  b.opening_balance
    + SUM(b.signed_amount)
        OVER (PARTITION BY b.server_account_id
              ORDER BY b.txn_date, b.server_txn_id
              ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)          AS running_balance
FROM base b;

CREATE INDEX IDX_MV_TXN_MIN_USER_DATE ON MV_TXN_MINIMAL(user_server_id, txn_date);

-------------------------------------------------------------------------------------------
-- run as SYSTEM@XEPDB1
GRANT CREATE MATERIALIZED VIEW TO KOSALA;
GRANT CREATE TABLE TO KOSALA;         -- MV needs segments
GRANT QUERY REWRITE TO KOSALA;        -- optional

-- give space in USERS tablespace so the MV can be stored
ALTER USER KOSALA QUOTA UNLIMITED ON USERS;
-- (or a cap) ALTER USER KOSALA QUOTA 500M ON USERS;