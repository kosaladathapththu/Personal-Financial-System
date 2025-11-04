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


