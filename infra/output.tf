output "vpc_id" {
  description = "ID da VPC do Kubernetes usada pelo Services MS"
  value       = data.aws_vpc.kubernetes.id
}

output "rds_endpoint" {
  description = "Endpoint do banco RDS"
  value       = aws_db_instance.db.address
}

output "rds_port" {
  description = "Porta do banco RDS"
  value       = aws_db_instance.db.port
}

output "rds_database_name" {
  description = "Nome do banco de dados"
  value       = aws_db_instance.db.db_name
}

output "sqs_service_orders_created_url" {
  description = "URL da fila FIFO de O.S. criadas (monólito -> MS)"
  value       = aws_sqs_queue.main["service_orders_created"].url
}

output "sqs_service_order_status_changed_url" {
  description = "URL da fila FIFO de mudança de status (MS -> monólito)"
  value       = aws_sqs_queue.main["service_order_status_changed"].url
}

output "sqs_dlq_urls" {
  description = "URLs das DLQs"
  value       = { for k, q in aws_sqs_queue.dlq : k => q.url }
}
