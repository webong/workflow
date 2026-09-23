package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/dunglas/frankenphp"
	"github.com/webong/workflow/mod/internal/rpc"
)

type phpInvoker struct {
	root string
}

func (p phpInvoker) Invoke(ctx context.Context, method string, params json.RawMessage) (json.RawMessage, *rpc.Error) {
	body, err := json.Marshal(struct {
		Method string          `json:"method"`
		Params json.RawMessage `json:"params"`
	}{Method: method, Params: params})
	if err != nil {
		return nil, &rpc.Error{Code: -32603, Message: "Internal error"}
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, "http://localhost/index.php", bytes.NewReader(body))
	if err != nil {
		return nil, &rpc.Error{Code: -32603, Message: "Internal error"}
	}
	request.Header.Set("Content-Type", "application/json")
	request, err = frankenphp.NewRequestWithContext(request, frankenphp.WithRequestDocumentRoot(p.root, false))
	if err != nil {
		return nil, &rpc.Error{Code: -32603, Message: "Internal error"}
	}
	response := httptest.NewRecorder()
	if err := frankenphp.ServeHTTP(response, request); err != nil || response.Code != http.StatusOK {
		slog.Error("PHP RPC invocation failed", "error", err, "status", response.Code)
		return nil, &rpc.Error{Code: -32603, Message: "Internal error"}
	}

	var result struct {
		Result json.RawMessage `json:"result"`
		Error  *rpc.Error      `json:"error"`
	}
	if err := json.Unmarshal(response.Body.Bytes(), &result); err != nil || result.Error == nil && len(result.Result) == 0 {
		slog.Error("PHP RPC returned an invalid response", "error", err)
		return nil, &rpc.Error{Code: -32603, Message: "Internal error"}
	}
	return result.Result, result.Error
}

func main() {
	if err := run(); err != nil {
		slog.Error("workflowd stopped", "error", err)
		os.Exit(1)
	}
}

func run() error {
	token := os.Getenv("WORKFLOW_RPC_TOKEN")
	bootstrap := os.Getenv("WORKFLOW_BOOTSTRAP")
	if token == "" || bootstrap == "" {
		return errors.New("WORKFLOW_RPC_TOKEN and WORKFLOW_BOOTSTRAP are required")
	}
	if _, err := os.Stat(bootstrap); err != nil {
		return fmt.Errorf("workflow bootstrap: %w", err)
	}

	root := os.Getenv("WORKFLOW_PHP_PUBLIC")
	if root == "" {
		root = "mod/php/public"
	}
	root, err := filepath.Abs(root)
	if err != nil {
		return err
	}
	if _, err := os.Stat(filepath.Join(root, "index.php")); err != nil {
		return fmt.Errorf("workflow PHP bridge: %w", err)
	}

	if err := frankenphp.Init(); err != nil {
		return err
	}
	defer frankenphp.Shutdown()

	handler, err := rpc.NewHandler(phpInvoker{root: root}, token)
	if err != nil {
		return err
	}
	mux := http.NewServeMux()
	mux.Handle("/rpc", handler)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			w.WriteHeader(http.StatusMethodNotAllowed)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	})

	listen := os.Getenv("WORKFLOW_RPC_LISTEN")
	if listen == "" {
		listen = "127.0.0.1:8080"
	}
	server := &http.Server{
		Addr:              listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      30 * time.Second,
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdownCtx)
	}()

	slog.Info("workflow JSON-RPC listening", "address", listen)
	err = server.ListenAndServe()
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
